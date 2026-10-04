<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\TenantSettingRepository;
use App\Repositories\BookingTypeRepository;
use App\Repositories\PaymentTypeRepository;

class SettingsController
{
    private Twig $view;
    private TenantSettingRepository $settingsRepo;
    private BookingTypeRepository $bookingTypesRepo;
    private PaymentTypeRepository $paymentTypesRepo;
    private \App\Repositories\ServiceCategoryRepository $serviceCategoryRepo;

    public function __construct(
        Twig $view, 
        TenantSettingRepository $settingsRepo,
        BookingTypeRepository $bookingTypesRepo,
        PaymentTypeRepository $paymentTypesRepo,
        \App\Repositories\ServiceCategoryRepository $serviceCategoryRepo
    ) {
        $this->view = $view;
        $this->settingsRepo = $settingsRepo;
        $this->bookingTypesRepo = $bookingTypesRepo;
        $this->paymentTypesRepo = $paymentTypesRepo;
        $this->serviceCategoryRepo = $serviceCategoryRepo;
    }

    private function checkSuperAdmin(Request $request, Response $response): ?Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'superadmin') {
            if ($request->getMethod() === 'GET') {
                return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
            }
            $response->getBody()->write('Forbidden: Only Super Admin can access settings.');
            return $response->withStatus(403);
        }
        return null;
    }

    public function index(Request $request, Response $response): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $tenantId = $request->getAttribute('tenant_id');
        $this->settingsRepo->setTenantId($tenantId);
        $this->bookingTypesRepo->setTenantId($tenantId);
        $this->paymentTypesRepo->setTenantId($tenantId);
        $this->serviceCategoryRepo->setTenantId($tenantId);

        $settings = $this->settingsRepo->getAll();

        if (!isset($settings['open_time'])) $settings['open_time'] = '09:00';
        if (!isset($settings['close_time'])) $settings['close_time'] = '17:00';
        if (!isset($settings['enable_queue_system'])) $settings['enable_queue_system'] = '1';
        if (!isset($settings['queue_ticket_prefix'])) $settings['queue_ticket_prefix'] = 'T';
        if (!isset($settings['queue_sound_enabled'])) $settings['queue_sound_enabled'] = '1';
        if (!isset($settings['queue_display_message'])) $settings['queue_display_message'] = 'Please wait for your ticket to be called.';

        $bookingTypes = $this->bookingTypesRepo->getAll();
        $paymentTypes = $this->paymentTypesRepo->getAll();
        $serviceCategories = $this->serviceCategoryRepo->getAll();

        return $this->view->render($response, 'settings/index.twig', [
            'title' => 'Settings',
            'active_menu' => 'settings',
            'settings' => $settings,
            'bookingTypes' => $bookingTypes,
            'paymentTypes' => $paymentTypes,
            'serviceCategories' => $serviceCategories
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $tenantId = $request->getAttribute('tenant_id');
        $this->settingsRepo->setTenantId($tenantId);

        $data = $request->getParsedBody();
        $this->settingsRepo->set('open_time', $data['open_time'] ?? '09:00');
        $this->settingsRepo->set('close_time', $data['close_time'] ?? '17:00');
        
        if (isset($data['loyalty_points_per_currency'])) {
            $this->settingsRepo->set('loyalty_points_per_currency', (int)$data['loyalty_points_per_currency']);
        }
        if (isset($data['loyalty_currency_per_point'])) {
            $this->settingsRepo->set('loyalty_currency_per_point', (float)$data['loyalty_currency_per_point']);
        }

        $this->settingsRepo->set('pos_show_cash', isset($data['pos_show_cash']) ? '1' : '0');
        $this->settingsRepo->set('pos_show_card', isset($data['pos_show_card']) ? '1' : '0');
        $this->settingsRepo->set('pos_show_bank_transfer', isset($data['pos_show_bank_transfer']) ? '1' : '0');
        $this->settingsRepo->set('pos_show_points', isset($data['pos_show_points']) ? '1' : '0');
        $this->settingsRepo->set('pos_show_credit', isset($data['pos_show_credit']) ? '1' : '0');
        $this->settingsRepo->set('pos_show_split', isset($data['pos_show_split']) ? '1' : '0');

        // Ticket / Queue System Settings
        $this->settingsRepo->set('enable_queue_system', isset($data['enable_queue_system']) ? '1' : '0');
        $this->settingsRepo->set('queue_ticket_prefix', !empty($data['queue_ticket_prefix']) ? strtoupper(trim($data['queue_ticket_prefix'])) : 'T');
        $this->settingsRepo->set('queue_sound_enabled', isset($data['queue_sound_enabled']) ? '1' : '0');
        $this->settingsRepo->set('queue_display_message', trim($data['queue_display_message'] ?? ''));

        $response->getBody()->write('
            <div id="form-messages" class="mb-4 p-3 rounded-lg bg-green-50 text-green-800 text-sm border border-green-200">
                Salon settings saved successfully!
            </div>
        ');
        return $response->withStatus(200);
    }

    // --- Booking Types CRUD ---

    public function storeBookingType(Request $request, Response $response): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->bookingTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        if (!empty($data['name'])) {
            try {
                $item = $this->bookingTypesRepo->create(['name' => trim($data['name'])]);
                return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'booking']);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response->getBody()->write('<div id="form-messages" hx-swap-oob="true" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">Booking type already exists!</div>');
                    return $response->withStatus(200);
                }
                throw $e;
            }
        }
        return $response->withStatus(400);
    }

    public function updateBookingType(Request $request, Response $response, array $args): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->bookingTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        $id = (int)$args['id'];
        if (!empty($data['name'])) {
            try {
                $this->bookingTypesRepo->update($id, ['name' => trim($data['name'])]);
                $item = $this->bookingTypesRepo->getById($id);
                return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'booking']);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response->getBody()->write('<div id="form-messages" hx-swap-oob="true" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">Booking type already exists!</div>');
                    // Return the existing item unmodified
                    $item = $this->bookingTypesRepo->getById($id);
                    return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'booking']);
                }
                throw $e;
            }
        }
        return $response->withStatus(400);
    }

    public function deleteBookingType(Request $request, Response $response, array $args): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->bookingTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $this->bookingTypesRepo->delete((int)$args['id']);
        return $response->withStatus(200);
    }

    // --- Payment Types CRUD ---

    public function storePaymentType(Request $request, Response $response): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->paymentTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        if (!empty($data['name'])) {
            try {
                $item = $this->paymentTypesRepo->create(['name' => trim($data['name'])]);
                return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'payment']);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response->getBody()->write('<div id="form-messages" hx-swap-oob="true" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">Payment type already exists!</div>');
                    return $response->withStatus(200);
                }
                throw $e;
            }
        }
        return $response->withStatus(400);
    }

    public function updatePaymentType(Request $request, Response $response, array $args): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->paymentTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        $id = (int)$args['id'];
        if (!empty($data['name'])) {
            try {
                $this->paymentTypesRepo->update($id, ['name' => trim($data['name'])]);
                $item = $this->paymentTypesRepo->getById($id);
                return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'payment']);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response->getBody()->write('<div id="form-messages" hx-swap-oob="true" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">Payment type already exists!</div>');
                    $item = $this->paymentTypesRepo->getById($id);
                    return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'payment']);
                }
                throw $e;
            }
        }
        return $response->withStatus(400);
    }

    public function deletePaymentType(Request $request, Response $response, array $args): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->paymentTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $this->paymentTypesRepo->delete((int)$args['id']);
        return $response->withStatus(200);
    }

    // --- Service Categories CRUD ---

    public function storeServiceCategory(Request $request, Response $response): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->serviceCategoryRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        if (!empty($data['name'])) {
            try {
                $item = $this->serviceCategoryRepo->create([
                    'name' => trim($data['name']),
                    'arabic_name' => isset($data['arabic_name']) ? trim($data['arabic_name']) : null
                ]);
                return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'service-categories', 'path' => 'service-categories']);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response->getBody()->write('<div id="form-messages" hx-swap-oob="true" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">Service category already exists!</div>');
                    return $response->withStatus(200);
                }
                throw $e;
            }
        }
        return $response->withStatus(400);
    }

    public function updateServiceCategory(Request $request, Response $response, array $args): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->serviceCategoryRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        $id = (int)$args['id'];
        if (!empty($data['name'])) {
            try {
                $this->serviceCategoryRepo->update($id, [
                    'name' => trim($data['name']),
                    'arabic_name' => isset($data['arabic_name']) ? trim($data['arabic_name']) : null
                ]);
                $item = $this->serviceCategoryRepo->getById($id);
                return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'service-categories', 'path' => 'service-categories']);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response->getBody()->write('<div id="form-messages" hx-swap-oob="true" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">Service category already exists!</div>');
                    $item = $this->serviceCategoryRepo->getById($id);
                    return $this->view->render($response, 'settings/type_item.twig', ['item' => $item, 'type' => 'service-categories', 'path' => 'service-categories']);
                }
                throw $e;
            }
        }
        return $response->withStatus(400);
    }

    public function deleteServiceCategory(Request $request, Response $response, array $args): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $this->serviceCategoryRepo->setTenantId($request->getAttribute('tenant_id'));
        $this->serviceCategoryRepo->delete((int)$args['id']);
        return $response->withStatus(200);
    }

    public function clearCache(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('role');
        $params = $request->getQueryParams();
        $token = $params['token'] ?? ($request->getHeaderLine('X-Deploy-Token') ?: '');
        $validToken = $_ENV['DEPLOY_CACHE_TOKEN'] ?? 'salongms_deploy_cache_clear';

        if ($role !== 'superadmin' && $token !== $validToken) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $result = \App\Services\CacheService::clearAll();
        TenantSettingRepository::clearRuntimeCache();

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Cache cleared successfully!',
            'details' => $result
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function clearCacheWeb(Request $request, Response $response): Response
    {
        if ($auth = $this->checkSuperAdmin($request, $response)) return $auth;

        $result = \App\Services\CacheService::clearAll();
        TenantSettingRepository::clearRuntimeCache();

        $html = '<div id="cache-message" class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-3 animate-fade-in shadow-sm">
            <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <div>
                <p class="font-bold">System cache cleared successfully!</p>
                <p class="text-xs text-emerald-700 mt-0.5">' . htmlspecialchars(implode(' ', $result['messages'])) . '</p>
            </div>
        </div>';

        $response->getBody()->write($html);
        return $response;
    }
}
