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

    public function index(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->settingsRepo->setTenantId($tenantId);
        $this->bookingTypesRepo->setTenantId($tenantId);
        $this->paymentTypesRepo->setTenantId($tenantId);
        $this->serviceCategoryRepo->setTenantId($tenantId);

        $settings = $this->settingsRepo->getAll();

        if (!isset($settings['open_time'])) $settings['open_time'] = '09:00';
        if (!isset($settings['close_time'])) $settings['close_time'] = '17:00';

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

        $response->getBody()->write('
            <div id="form-messages" class="mb-4 p-3 rounded-lg bg-green-50 text-green-800 text-sm border border-green-200">
                Business hours saved successfully!
            </div>
        ');
        return $response->withStatus(200);
    }

    // --- Booking Types CRUD ---

    public function storeBookingType(Request $request, Response $response): Response
    {
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
        $this->bookingTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $this->bookingTypesRepo->delete((int)$args['id']);
        return $response->withStatus(200);
    }

    // --- Payment Types CRUD ---

    public function storePaymentType(Request $request, Response $response): Response
    {
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
        $this->paymentTypesRepo->setTenantId($request->getAttribute('tenant_id'));
        $this->paymentTypesRepo->delete((int)$args['id']);
        return $response->withStatus(200);
    }

    // --- Service Categories CRUD ---

    public function storeServiceCategory(Request $request, Response $response): Response
    {
        $this->serviceCategoryRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        if (!empty($data['name'])) {
            try {
                $item = $this->serviceCategoryRepo->create(['name' => trim($data['name'])]);
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
        $this->serviceCategoryRepo->setTenantId($request->getAttribute('tenant_id'));
        $data = $request->getParsedBody();
        $id = (int)$args['id'];
        if (!empty($data['name'])) {
            try {
                $this->serviceCategoryRepo->update($id, ['name' => trim($data['name'])]);
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
        $this->serviceCategoryRepo->setTenantId($request->getAttribute('tenant_id'));
        $this->serviceCategoryRepo->delete((int)$args['id']);
        return $response->withStatus(200);
    }
}
