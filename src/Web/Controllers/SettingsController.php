<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\TenantSettingRepository;

class SettingsController
{
    private Twig $view;
    private TenantSettingRepository $settingsRepo;

    public function __construct(Twig $view, TenantSettingRepository $settingsRepo)
    {
        $this->view = $view;
        $this->settingsRepo = $settingsRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->settingsRepo->setTenantId($tenantId);

        $settings = $this->settingsRepo->getAll();

        // Defaults
        if (!isset($settings['open_time'])) {
            $settings['open_time'] = '09:00';
        }
        if (!isset($settings['close_time'])) {
            $settings['close_time'] = '17:00';
        }

        return $this->view->render($response, 'settings/index.twig', [
            'title' => 'Settings',
            'active_menu' => 'settings',
            'settings' => $settings
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->settingsRepo->setTenantId($tenantId);

        $data = $request->getParsedBody();
        
        $openTime = $data['open_time'] ?? '09:00';
        $closeTime = $data['close_time'] ?? '17:00';

        $this->settingsRepo->set('open_time', $openTime);
        $this->settingsRepo->set('close_time', $closeTime);

        // Add a success message to form
        $response->getBody()->write('
            <div id="form-messages" class="mb-4 p-3 rounded-lg bg-green-50 text-green-800 text-sm border border-green-200">
                Settings saved successfully!
            </div>
        ');
        return $response->withStatus(200);
    }
}
