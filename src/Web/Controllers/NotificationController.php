<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\NotificationRepository;

class NotificationController
{
    private Twig $view;
    private NotificationRepository $notificationRepo;

    public function __construct(Twig $view, NotificationRepository $notificationRepo)
    {
        $this->view = $view;
        $this->notificationRepo = $notificationRepo;
    }

    public function getDropdown(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->notificationRepo->setTenantId($tenantId);

        $notifications = $this->notificationRepo->getRecent(10);
        $unreadCount = $this->notificationRepo->getUnreadCount();

        return $this->view->render($response, 'notifications/dropdown.twig', [
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
    }

    public function getBadge(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->notificationRepo->setTenantId($tenantId);
        
        $unreadCount = $this->notificationRepo->getUnreadCount();
        
        if ($unreadCount > 0) {
            $response->getBody()->write('<span class="absolute top-2 right-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white transform translate-x-1/4 -translate-y-1/4 bg-red-600 rounded-full">' . $unreadCount . '</span>');
        }
        
        return $response;
    }

    public function markAsRead(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->notificationRepo->setTenantId($tenantId);

        $id = (int)$args['id'];
        $this->notificationRepo->markAsRead($id);

        return $response->withStatus(200);
    }
    
    public function markAllAsRead(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->notificationRepo->setTenantId($tenantId);

        $this->notificationRepo->markAllAsRead();

        return $this->getDropdown($request, $response);
    }
}
