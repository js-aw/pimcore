<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\ExtensionManager;

use Doctrine\DBAL\Migrations\OutputWriter;
use ForceUTF8\Encoding;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\AdminBundle\HttpFoundation\JsonResponse;
use Pimcore\Bundle\LegacyBundle\Controller\Admin\ExtensionManager\LegacyExtensionManagerController;
use Pimcore\Controller\EventedControllerInterface;
use Pimcore\Extension\Bundle\Exception\BundleNotFoundException;
use Pimcore\Extension\Bundle\Installer\MigrationInstallerInterface;
use Pimcore\Extension\Bundle\PimcoreBundleInterface;
use Pimcore\Extension\Bundle\PimcoreBundleManager;
use Pimcore\Extension\Document\Areabrick\AreabrickInterface;
use Pimcore\Extension\Document\Areabrick\AreabrickManager;
use Pimcore\Routing\RouteReferenceInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Handles all "new" extensions as of pimcore 5 (bundles, new areabrick layout) and pipes legacy extension requests
 * to legacy controller when the legacy bundle is enabled.
 */
class ExtensionManagerController extends AdminController implements EventedControllerInterface
{
    /**
     * @var PimcoreBundleManager
     */
    protected $bundleManager;

    /**
     * @var AreabrickManager
     */
    protected $areabrickManager;

    /**
     * @inheritDoc
     */
    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        $this->bundleManager    = $this->get('pimcore.extension.bundle_manager');
        $this->areabrickManager = $this->get('pimcore.area.brick_manager');
    }

    /**
     * @inheritDoc
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        $this->checkPermission('plugins');
    }

    /**
     * @inheritDoc
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        // noop
    }

    /**
     * @Route("/admin/extensions")
     * @Method("GET")
     *
     * @return JsonResponse
     */
    public function getExtensionsAction()
    {
        $extensions = array_merge(
            $this->getBundleList(),
            $this->getBrickList()
        );

        $legacyController = $this->getLegacyController();
        if ($legacyController) {
            $extensions = array_merge($extensions, $legacyController->getExtensions());
        }

        return $this->json(['extensions' => $extensions]);
    }

    /**
     * Updates bundle options (priority, environments)
     *
     * @Route("/admin/extensions")
     * @Method("PUT")
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateExtensionsAction(Request $request)
    {
        $data = $this->decodeJson($request->getContent());

        if (!is_array($data) || !isset($data['extensions']) || !is_array($data['extensions'])) {
            throw new BadRequestHttpException('Invalid data. Need an array of extensions to update.');
        }

        $updates = [];
        foreach ($data['extensions'] as $row) {
            if (!$row || !is_array($row) || !isset($row['id'])) {
                throw new BadRequestHttpException('Invalid data. Missing row ID.');
            }

            $id = (string)$row['id'];

            $options = [];
            if (isset($row['environments'])) {
                $environments = explode(',', $row['environments']);
                $environments = array_map(function ($item) {
                    return trim((string)$item);
                }, $environments);

                $options['environments'] = $environments;
            }

            if (isset($row['priority'])) {
                $options['priority'] = (int)$row['priority'];
            }

            $updates[$id] = $options;
        }

        $this->bundleManager->setStates($updates);

        return $this->json([
            'extensions' => $this->getBundleList(array_keys($updates))
        ]);
    }

    /**
     * @Route("/admin/toggle-extension-state")
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function toggleExtensionStateAction(Request $request)
    {
        if (null !== $response = $this->handleLegacyRequest($request, __FUNCTION__)) {
            return $response;
        }

        $type   = $request->get('type');
        $id     = $request->get('id');
        $enable = $request->get('method', 'enable') === 'enable' ? true : false;

        $reload  = false;
        $message = null;

        if ($type === 'bundle') {
            $this->bundleManager->setState($id, ['enabled' => $enable]);
            $reload = true;

            if ($enable) {
                $message = $this->installAssets();
            }
        } elseif ($type === 'areabrick') {
            $this->areabrickManager->setState($id, $enable);
            $reload = true;
        }

        $data = [
            'success' => true,
            'reload'  => $reload,
        ];

        if ($message) {
            $data['message'] = $message;
        }

        return $this->json($data);
    }

    /**
     * Runs array:install command and returns its result as array (line-by-line)
     *
     * @return array
     */
    private function installAssets(): array
    {
        $assetsInstaller = $this->get('pimcore.tool.assets_installer');

        try {
            $installProcess = $assetsInstaller->install();

            $message = str_replace("'", '', $installProcess->getCommandLine()) . PHP_EOL . $installProcess->getOutput();
        } catch (ProcessFailedException $e) {
            $message = 'Failed to run assets:install command. Please run command manually.' . PHP_EOL . PHP_EOL . $e->getMessage();
        }

        $message = Encoding::fixUTF8($message);
        $message = (new AnsiToHtmlConverter())->convert($message);
        $message = explode(PHP_EOL, $message);

        return $message;
    }

    /**
     * @Route("/admin/install")
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function installAction(Request $request)
    {
        if (null !== $response = $this->handleLegacyRequest($request, __FUNCTION__)) {
            return $response;
        }

        return $this->handleInstallation($request, true);
    }

    /**
     * @Route("/admin/uninstall")
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function uninstallAction(Request $request)
    {
        if (null !== $response = $this->handleLegacyRequest($request, __FUNCTION__)) {
            return $response;
        }

        return $this->handleInstallation($request, false);
    }

    /**
     * @Route("/admin/update")
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateAction(Request $request)
    {
        try {
            $bundle = $this->bundleManager->getActiveBundle($request->get('id'), false);
            $output = $this->setupInstallerOutput($bundle);

            $this->bundleManager->update($bundle);

            $data = [
                'success' => true,
                'bundle'  => $this->buildBundleInfo($bundle, true, true)
            ];

            if (!empty($message = $output->fetch())) {
                $data['message'] = (new AnsiToHtmlConverter())->convert($message);
            }

            return $this->json($data);
        } catch (BundleNotFoundException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * @return LegacyExtensionManagerController|null
     */
    private function getLegacyController()
    {
        if (\Pimcore::isLegacyModeAvailable()) {
            if ($this->container->has('pimcore.legacy.controller.admin.extension_manager')) {
                return $this->get('pimcore.legacy.controller.admin.extension_manager');
            }
        }
    }

    /**
     * Pipe request to legacy controller
     *
     * @param Request $request
     * @param $method
     *
     * @return JsonResponse|null
     */
    private function handleLegacyRequest(Request $request, $method)
    {
        if ($request->get('extensionType') !== 'legacy') {
            return null;
        }

        $legacyController = $this->getLegacyController();
        if (!$legacyController) {
            throw new BadRequestHttpException(sprintf('Tried to call to legacy extension action %s, but legacy controller was not found', $method));
        }

        if (!method_exists($legacyController, $method)) {
            throw new BadRequestHttpException(sprintf('Legacy extension action %s, does not exist on legacy controller', $method));
        }

        return call_user_func_array([$legacyController, $method], [$request]);
    }

    /**
     * @param Request $request
     * @param bool $install
     *
     * @return JsonResponse
     */
    private function handleInstallation(Request $request, $install = true)
    {
        try {
            $bundle = $this->bundleManager->getActiveBundle($request->get('id'), false);
            $output = $this->setupInstallerOutput($bundle);

            if ($install) {
                $this->bundleManager->install($bundle);
            } else {
                $this->bundleManager->uninstall($bundle);
            }

            $data = [
                'success' => true,
                'reload'  => $this->bundleManager->needsReloadAfterInstall($bundle)
            ];

            if (!empty($message = $output->fetch())) {
                $data['message'] = (new AnsiToHtmlConverter())->convert($message);
            }

            return $this->json($data);
        } catch (BundleNotFoundException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * @param array $filter
     *
     * @return array
     */
    private function getBundleList(array $filter = [])
    {
        $bm = $this->bundleManager;

        $results = [];
        foreach ($bm->getEnabledBundleNames() as $className) {
            try {
                $bundle = $bm->getActiveBundle($className, false);

                $results[$bm->getBundleIdentifier($bundle)] = $this->buildBundleInfo($bundle, true, $bm->isInstalled($bundle));
            } catch (\Throwable $e) {
                $this->get('monolog.logger.pimcore')->error($e);
            }
        }

        foreach ($bm->getAvailableBundles() as $className) {
            // bundle is enabled
            if (array_key_exists($className, $results)) {
                continue;
            }

            $bundle = $this->buildBundleInstance($className);
            if ($bundle) {
                $results[$bm->getBundleIdentifier($bundle)] = $this->buildBundleInfo($bundle);
            }
        }

        $results = array_values($results);

        if (count($filter) > 0) {
            $results = array_filter($results, function (array $item) use ($filter) {
                return in_array($item['id'], $filter);
            });
        }

        // show enabled/active first, then order by priority for
        // bundles with the same enabled state
        usort($results, function ($a, $b) {
            if ($a['active'] && !$b['active']) {
                return -1;
            }

            if (!$a['active'] && $b['active']) {
                return 1;
            }

            if ($a['active'] === $b['active']) {
                if ($a['priority'] === $b['priority']) {
                    return 0;
                }

                // reverse sorty by priority -> higher comes first
                return $a['priority'] < $b['priority'] ? 1 : -1;
            }
        });

        return $results;
    }

    /**
     * @param $bundleName
     *
     * @return PimcoreBundleInterface
     */
    private function buildBundleInstance($bundleName)
    {
        try {
            /** @var PimcoreBundleInterface $bundle */
            $bundle = new $bundleName();
            $bundle->setContainer($this->container);

            return $bundle;
        } catch (\Exception $e) {
            $this->get('monolog.logger.pimcore')->error('Failed to build instance of bundle {bundle}: {error}', [
                'bundle' => $bundleName,
                'error'  => $e->getMessage()
            ]);
        }
    }

    /**
     * @param PimcoreBundleInterface $bundle
     * @param bool $enabled
     * @param bool $installed
     *
     * @return array
     */
    private function buildBundleInfo(PimcoreBundleInterface $bundle, $enabled = false, $installed = false)
    {
        $bm = $this->bundleManager;

        $state = $bm->getState($bundle);

        $info = [
            'id'            => $bm->getBundleIdentifier($bundle),
            'type'          => 'bundle',
            'name'          => !empty($bundle->getNiceName()) ? $bundle->getNiceName() : $bundle->getName(),
            'description'   => $bundle->getDescription(),
            'active'        => $enabled,
            'installable'   => false,
            'uninstallable' => false,
            'updateable'    => false,
            'installed'     => $installed,
            'configuration' => $this->getIframePath($bundle),
            'version'       => $bundle->getVersion(),
            'priority'      => $state['priority'],
            'environments'  => implode(', ', $state['environments'])
        ];

        // only check for installation specifics if the bundle is enabled
        if ($enabled) {
            $info = array_merge($info, [
                'installable'   => $bm->canBeInstalled($bundle),
                'uninstallable' => $bm->canBeUninstalled($bundle),
                'updateable'    => $bm->canBeUpdated($bundle),
            ]);
        }

        return $info;
    }

    /**
     * @param PimcoreBundleInterface $bundle
     *
     * @return string|null
     */
    private function getIframePath(PimcoreBundleInterface $bundle)
    {
        if ($iframePath = $bundle->getAdminIframePath()) {
            if ($iframePath instanceof RouteReferenceInterface) {
                return $this->get('router')->generate(
                    $iframePath->getRoute(),
                    $iframePath->getParameters(),
                    $iframePath->getType()
                );
            }

            if (!empty($iframePath)) {
                return $iframePath;
            }
        }
    }

    /**
     * @return array
     */
    private function getBrickList()
    {
        $am = $this->get('pimcore.area.brick_manager');

        $results = [];
        foreach ($am->getBricks() as $brick) {
            $results[] = $this->buildBrickInfo($brick);
        }

        return $results;
    }

    /**
     * @param AreabrickInterface $brick
     *
     * @return array
     */
    private function buildBrickInfo(AreabrickInterface $brick)
    {
        return [
            'id'            => $brick->getId(),
            'type'          => 'areabrick',
            'name'          => $this->trans($brick->getName()),
            'description'   => $this->trans($brick->getDescription()),
            'installable'   => false,
            'uninstallable' => false,
            'updateable'    => false,
            'installed'     => true,
            'active'        => $this->areabrickManager->isEnabled($brick->getId()),
            'version'       => $brick->getVersion()
        ];
    }

    /**
     * Sets a buffered output writer on the migration configuration which can be used
     * to fetch messages which happened during migration
     *
     * @param PimcoreBundleInterface $bundle
     * @return BufferedOutput
     */
    private function setupInstallerOutput(PimcoreBundleInterface $bundle): BufferedOutput
    {
        $output = new BufferedOutput(Output::VERBOSITY_NORMAL, true);

        $installer = $bundle->getInstaller();
        if (null === $installer || !$installer instanceof MigrationInstallerInterface) {
            return $output;
        }

        $outputWriter = new OutputWriter(function ($message) use ($output) {
            $output->writeln($message);
        });

        $configuration = $installer->getMigrationConfiguration();
        $configuration->setOutputWriter($outputWriter);

        return $output;
    }
}
