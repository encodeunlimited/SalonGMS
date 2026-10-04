<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\QueueRepository;
use App\Repositories\UserRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\TenantSettingRepository;
use Exception;

class QueueController
{
    private Twig $view;
    private QueueRepository $queueRepo;
    private UserRepository $userRepo;
    private ServiceRepository $serviceRepo;
    private CustomerRepository $customerRepo;
    private TenantSettingRepository $settingsRepo;

    public function __construct(
        Twig $view,
        QueueRepository $queueRepo,
        UserRepository $userRepo,
        ServiceRepository $serviceRepo,
        CustomerRepository $customerRepo,
        TenantSettingRepository $settingsRepo
    ) {
        $this->view = $view;
        $this->queueRepo = $queueRepo;
        $this->userRepo = $userRepo;
        $this->serviceRepo = $serviceRepo;
        $this->customerRepo = $customerRepo;
        $this->settingsRepo = $settingsRepo;
    }

    private function setTenant(Request $request): int
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->queueRepo->setTenantId($tenantId);
        $this->userRepo->setTenantId($tenantId);
        $this->serviceRepo->setTenantId($tenantId);
        $this->customerRepo->setTenantId($tenantId);
        $this->settingsRepo->setTenantId($tenantId);
        return $tenantId;
    }

    /**
     * Main Queue Dashboard & Lane Board
     */
    public function index(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $settings = $this->settingsRepo->getAll();
        $isEnabled = ($settings['enable_queue_system'] ?? '1') === '1';

        $userRole = $request->getAttribute('role') ?? 'stylist';
        $userId = (int)$request->getAttribute('user_id');

        if (!$isEnabled) {
            return $this->view->render($response, 'queue/disabled.twig', [
                'title' => 'Queue System Disabled',
                'active_menu' => 'queue'
            ]);
        }

        $isStylist = ($userRole === 'stylist');
        $barberFilterId = $isStylist ? $userId : null;

        $barberQueues = $this->queueRepo->getBarberQueues(null, $barberFilterId);
        $stats = $this->queueRepo->getQueueStats(null, $barberFilterId);

        // Get barbers for issue modal (stylists and managers)
        $allUsers = $this->userRepo->getAll();
        $allBarbers = array_filter($allUsers, function($u) {
            return in_array($u['role'], ['stylist', 'manager', 'admin']);
        });

        // Calculate active waiting count per barber for easy selection
        $allBarberQueues = $isStylist ? $this->queueRepo->getBarberQueues() : $barberQueues;
        $barberQueueMap = [];
        foreach ($allBarberQueues as $bq) {
            $barberQueueMap[$bq['barber']['id']] = [
                'waiting' => $bq['total_waiting'],
                'serving' => !empty($bq['serving'])
            ];
        }

        foreach ($allBarbers as &$b) {
            $b['waiting_count'] = $barberQueueMap[$b['id']]['waiting'] ?? 0;
            $b['is_busy'] = $barberQueueMap[$b['id']]['serving'] ?? false;
        }
        unset($b);

        // Stylists only see themselves in Issue Modal (Cashiers/Admins see all)
        $barbers = $isStylist 
            ? array_values(array_filter($allBarbers, fn($u) => (int)$u['id'] === $userId))
            : array_values($allBarbers);

        $services = $this->serviceRepo->getAll();
        $customers = $this->customerRepo->getAll();

        return $this->view->render($response, 'queue/index.twig', [
            'title' => $isStylist ? 'My Station & Queue' : 'Queue & Waiting Management',
            'active_menu' => 'queue',
            'barber_queues' => $barberQueues,
            'stats' => $stats,
            'barbers' => $barbers,
            'all_barbers' => array_values($allBarbers),
            'services' => $services,
            'customers' => $customers,
            'settings' => $settings,
            'current_user_id' => $userId,
            'current_user_role' => $userRole,
            'is_stylist' => $isStylist
        ]);
    }

    /**
     * Partial update for Barber Lanes (Used by HTMX auto-refresh)
     */
    public function lanes(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $userRole = $request->getAttribute('role') ?? 'stylist';
        $userId = (int)$request->getAttribute('user_id');

        $isStylist = ($userRole === 'stylist');
        $barberFilterId = $isStylist ? $userId : null;

        $barberQueues = $this->queueRepo->getBarberQueues(null, $barberFilterId);
        $stats = $this->queueRepo->getQueueStats(null, $barberFilterId);
        $settings = $this->settingsRepo->getAll();

        return $this->view->render($response, 'queue/partials/lanes.twig', [
            'barber_queues' => $barberQueues,
            'stats' => $stats,
            'settings' => $settings,
            'current_user_id' => $userId,
            'current_user_role' => $userRole,
            'is_stylist' => $isStylist
        ]);
    }

    /**
     * Sidebar badge counter
     */
    public function badge(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $userRole = $request->getAttribute('role') ?? 'stylist';
        $userId = (int)$request->getAttribute('user_id');

        $isStylist = ($userRole === 'stylist');
        $barberFilterId = $isStylist ? $userId : null;

        $stats = $this->queueRepo->getQueueStats(null, $barberFilterId);
        $waiting = $stats['waiting_count'] ?? 0;

        $colorClass = $waiting > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600';
        $html = sprintf(
            '<span id="nav-queue-count" class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold %s" hx-get="/web/queue/badge" hx-trigger="load, every 15s" hx-swap="outerHTML">%d</span>',
            $colorClass,
            $waiting
        );

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Estimate wait time & next ticket preview for chosen barber
     */
    public function estimate(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $barberId = (int)($request->getQueryParams()['barber_id'] ?? 0);
        $settings = $this->settingsRepo->getAll();
        $prefix = $settings['queue_ticket_prefix'] ?? 'T';

        if ($barberId <= 0) {
            $payload = json_encode(['error' => 'Invalid barber']);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $info = $this->queueRepo->getNextTicketInfo($barberId, $prefix);
        $response->getBody()->write(json_encode($info));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get specific services for a chosen barber (filtered by specialist_areas if configured)
     */
    public function barberServices(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $barberId = (int)($args['id'] ?? 0);

        $user = $this->userRepo->getById($barberId);
        $allServices = $this->serviceRepo->getAll();

        $services = [];
        if ($user && !empty($user['specialist_areas']) && is_array($user['specialist_areas'])) {
            $specialistAreas = array_map('strval', $user['specialist_areas']);
            foreach ($allServices as $s) {
                if (in_array((string)$s['id'], $specialistAreas, true)) {
                    $services[] = $s;
                }
            }
        } else {
            // If no specialist areas restricted or user is admin/manager, show all services
            $services = $allServices;
        }

        $output = array_values(array_map(function($s) {
            return [
                'id' => (int)$s['id'],
                'name' => $s['name'],
                'category' => $s['category'] ?? '',
                'price' => (float)$s['price'],
                'duration_minutes' => (int)($s['duration_minutes'] ?? 30)
            ];
        }, $services));

        $response->getBody()->write(json_encode($output));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Issue a new ticket for an arriving customer
     */
    public function issueTicket(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $data = $request->getParsedBody();

        $barberId = (int)($data['barber_id'] ?? 0);
        $customerName = trim($data['customer_name'] ?? '');
        $customerPhone = trim($data['customer_phone'] ?? '');
        $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
        $serviceId = !empty($data['service_id']) ? (int)$data['service_id'] : null;
        $notes = trim($data['notes'] ?? '');

        // If existing customer selected, lookup their name/phone if not manually altered
        if ($customerId) {
            $cust = $this->customerRepo->getById($customerId);
            if ($cust) {
                if (empty($customerName)) $customerName = $cust['name'];
                if (empty($customerPhone)) $customerPhone = $cust['phone'];
            }
        } elseif (!empty($customerPhone) && !empty($customerName)) {
            // Optional: check if customer already exists by phone
            $existing = $this->customerRepo->getByPhone($customerPhone);
            if ($existing) {
                $customerId = (int)$existing['id'];
            }
        }

        if (empty($customerName)) {
            $customerName = 'Walk-in Customer';
        }

        if ($barberId <= 0) {
            $response->getBody()->write('
                <div id="issue-messages" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">
                    Please select a barber / stylist!
                </div>
            ');
            return $response->withStatus(400);
        }

        // Get service name
        $serviceName = null;
        if ($serviceId) {
            $srv = $this->serviceRepo->getById($serviceId);
            if ($srv) {
                $serviceName = $srv['name'];
            }
        }

        $settings = $this->settingsRepo->getAll();
        $prefix = $settings['queue_ticket_prefix'] ?? 'T';

        $ticket = $this->queueRepo->issueTicket([
            'barber_id' => $barberId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'notes' => $notes,
            'prefix' => $prefix
        ]);

        // If HTMX request, render ticket confirmation modal
        if ($request->getHeaderLine('HX-Request')) {
            return $this->view->render($response, 'queue/partials/ticket_modal.twig', [
                'ticket' => $ticket,
                'settings' => $settings
            ]);
        }

        return $response->withHeader('Location', '/web/queue?issued=' . $ticket['id'])->withStatus(302);
    }

    /**
     * Printable ticket slip
     */
    public function ticketSlip(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $ticketId = (int)$args['id'];
        $ticket = $this->queueRepo->getTicketDetails($ticketId);

        if (!$ticket) {
            $response->getBody()->write('Ticket not found.');
            return $response->withStatus(404);
        }

        $settings = $this->settingsRepo->getAll();

        return $this->view->render($response, 'queue/ticket_slip.twig', [
            'ticket' => $ticket,
            'settings' => $settings
        ]);
    }

    /**
     * Call a specific ticket
     */
    public function callTicket(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $ticketId = (int)$args['id'];

        $this->queueRepo->callTicket($ticketId);

        if ($request->getHeaderLine('HX-Request')) {
            return $this->lanes($request, $response);
        }
        return $response->withHeader('Location', '/web/queue')->withStatus(302);
    }

    /**
     * Call next customer in line for a specific barber
     */
    public function callNext(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $barberId = (int)$args['barber_id'];

        $called = $this->queueRepo->callNextForBarber($barberId);

        if ($request->getHeaderLine('HX-Request')) {
            return $this->lanes($request, $response);
        }
        return $response->withHeader('Location', '/web/queue')->withStatus(302);
    }

    /**
     * Mark ticket as completed
     */
    public function completeTicket(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $ticketId = (int)$args['id'];

        $this->queueRepo->completeTicket($ticketId);

        if ($request->getHeaderLine('HX-Request')) {
            return $this->lanes($request, $response);
        }
        return $response->withHeader('Location', '/web/queue')->withStatus(302);
    }

    /**
     * Cancel or mark no-show for a ticket
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $ticketId = (int)$args['id'];
        $data = $request->getParsedBody();
        $status = $data['status'] ?? 'cancelled';
        $reason = $data['reason'] ?? null;

        $this->queueRepo->updateTicketStatus($ticketId, $status, $reason);

        if ($request->getHeaderLine('HX-Request')) {
            return $this->lanes($request, $response);
        }
        return $response->withHeader('Location', '/web/queue')->withStatus(302);
    }

    /**
     * Transfer ticket to another barber
     */
    public function transferBarber(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->setTenant($request);
        $ticketId = (int)$args['id'];
        $data = $request->getParsedBody();
        $newBarberId = (int)($data['new_barber_id'] ?? 0);

        if ($newBarberId > 0) {
            $this->queueRepo->transferBarber($ticketId, $newBarberId);
        }

        if ($request->getHeaderLine('HX-Request')) {
            return $this->lanes($request, $response);
        }
        return $response->withHeader('Location', '/web/queue')->withStatus(302);
    }

    /**
     * Public Waiting Lounge Fullscreen Display
     */
    public function display(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $settings = $this->settingsRepo->getAll();
        $data = $this->queueRepo->getPublicDisplayData();

        return $this->view->render($response, 'queue/display.twig', [
            'title' => 'Waiting Lounge Display',
            'display_data' => $data,
            'settings' => $settings
        ]);
    }

    /**
     * Public Display Polling JSON API
     */
    public function displayData(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $data = $this->queueRepo->getPublicDisplayData();
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Queue History and Logs
     */
    public function history(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $userRole = $request->getAttribute('role') ?? 'stylist';
        $userId = (int)$request->getAttribute('user_id');
        $isStylist = ($userRole === 'stylist');

        $params = $request->getQueryParams();

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $filters = [
            'date' => $params['date'] ?? date('Y-m-d'),
            'barber_id' => $isStylist ? $userId : ($params['barber_id'] ?? ''),
            'status' => $params['status'] ?? '',
            'search' => $params['search'] ?? ''
        ];

        $historyData = $this->queueRepo->getHistory($filters, $limit, $offset);
        $allUsers = $this->userRepo->getAll();
        $barbers = array_filter($allUsers, function($u) {
            return in_array($u['role'], ['stylist', 'manager', 'admin']);
        });

        $totalPages = ceil($historyData['total'] / $limit);

        return $this->view->render($response, 'queue/history.twig', [
            'title' => 'Queue History & Logs',
            'active_menu' => 'queue',
            'tickets' => $historyData['data'],
            'total' => $historyData['total'],
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'barbers' => array_values($barbers),
            'is_stylist' => $isStylist,
            'current_user_id' => $userId
        ]);
    }

    /**
     * Quick Customer search for modal
     */
    public function searchCustomers(Request $request, Response $response): Response
    {
        $tenantId = $this->setTenant($request);
        $q = trim($request->getQueryParams()['q'] ?? '');

        if (strlen($q) < 1) {
            $response->getBody()->write(json_encode([]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $customers = $this->customerRepo->getAll(['search' => $q]);
        $results = array_map(function($c) {
            return [
                'id' => $c['id'],
                'name' => $c['name'],
                'phone' => $c['phone'],
                'vip' => !empty($c['vip_status'])
            ];
        }, array_slice($customers, 0, 10));

        $response->getBody()->write(json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
