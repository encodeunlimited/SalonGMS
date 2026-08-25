<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\InventoryRepository;

class InventoryController
{
    private Twig $view;
    private InventoryRepository $inventory;
    private \App\Repositories\ExpenseRepository $expenseRepo;

    public function __construct(Twig $view, InventoryRepository $inventory, \App\Repositories\ExpenseRepository $expenseRepo)
    {
        $this->view = $view;
        $this->inventory = $inventory;
        $this->expenseRepo = $expenseRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'id',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => []
        ];
        
        $paginated = $this->inventory->getPaginated($options);

        return $this->view->render($response, 'inventory/index.twig', [
            'title' => 'Inventory',
            'active_menu' => 'inventory',
            'items' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir']
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'inventory/modal.twig');
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        
        $item = $this->inventory->create([
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => 0, // Initial stock is 0
            'price' => (float)$data['price']
        ]);

        $rowHtml = $this->view->fetch('inventory/row.twig', ['item' => $item]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="afterbegin:#inventory-table-body" id=', $rowHtml);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . $rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => 'Inventory item added successfully!']
                        ]));
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        $item = $this->inventory->getById($itemId);
        
        if (!$item) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('inventory/modal.twig', [
            'item' => $item
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        $data = $request->getParsedBody();
        
        $existingItem = $this->inventory->getById($itemId);

        $item = $this->inventory->update($itemId, [
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => $existingItem['quantity'], // Quantity remains unchanged during edit
            'price' => (float)$data['price'],
            'expiry_date' => $existingItem['expiry_date']
        ]);

        $rowHtml = $this->view->fetch('inventory/row.twig', ['item' => $item]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="outerHTML:#inventory-row-' . $itemId . '" id=', $rowHtml);
        
        $response->getBody()->write($rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => 'Inventory item updated successfully!']
                        ]));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }
        
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        
        try {
            $this->inventory->delete($itemId);
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Inventory item deleted successfully!']
                            ]));
        } catch (\Exception $e) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Error deleting inventory item: ' . $e->getMessage(), 'type' => 'error']
                            ]));
        }
    }

    public function issueForm(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        $item = $this->inventory->getById($itemId);
        
        if (!$item) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('inventory/issue_modal.twig', [
            'item' => $item
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function issue(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        $item = $this->inventory->getById($itemId);
        
        if (!$item) {
            return $response->withStatus(404);
        }

        $data = $request->getParsedBody();
        $issueQuantity = (int)($data['issue_quantity'] ?? 0);

        if ($issueQuantity <= 0 || $issueQuantity > $item['quantity']) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Invalid issue quantity!', 'type' => 'error']
                            ]));
        }

        // Deduct quantity
        $newQuantity = $item['quantity'] - $issueQuantity;
        
        $updatedItem = $this->inventory->update($itemId, [
            'name' => $item['name'],
            'sku' => $item['sku'],
            'description' => $item['description'],
            'quantity' => $newQuantity,
            'price' => $item['price']
        ]);

        $rowHtml = $this->view->fetch('inventory/row.twig', ['item' => $updatedItem]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="outerHTML:#inventory-row-' . $itemId . '" id=', $rowHtml);
        
        $response->getBody()->write($rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => "Issued {$issueQuantity} of {$item['name']} successfully!"]
                        ]));
    public function stockInForm(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }

        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        $item = $this->inventory->getById($itemId);
        
        if (!$item) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('inventory/stock_in_modal.twig', [
            'item' => $item
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function stockIn(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }

        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        $this->expenseRepo->setTenantId($tenantId);
        
        $itemId = (int) $args['id'];
        $item = $this->inventory->getById($itemId);
        
        if (!$item) {
            return $response->withStatus(404);
        }

        $data = $request->getParsedBody();
        $qtyReceived = (int)($data['quantity_received'] ?? 0);
        $totalCost = (float)($data['total_cost'] ?? 0);
        $paymentMethod = $data['payment_method'] ?? 'Cash';
        $reference = $data['reference'] ?? '';
        $expiryDate = !empty($data['expiry_date']) ? $data['expiry_date'] : ($item['expiry_date'] ?? null);

        if ($qtyReceived <= 0 || $totalCost < 0) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Invalid quantity or cost!', 'type' => 'error']
                            ]));
        }

        // 1. Update Inventory Quantity and Expiry
        $newQuantity = $item['quantity'] + $qtyReceived;
        $updatedItem = $this->inventory->update($itemId, [
            'name' => $item['name'],
            'sku' => $item['sku'],
            'description' => $item['description'],
            'quantity' => $newQuantity,
            'price' => $item['price'],
            'expiry_date' => $expiryDate
        ]);

        // 2. Record Expense
        $desc = "GRN: {$qtyReceived}x {$item['name']}";
        if ($reference) {
            $desc .= " (Ref: {$reference})";
        }
        
        $this->expenseRepo->create([
            'expense_date' => date('Y-m-d'),
            'category' => 'Inventory Purchase',
            'amount' => $totalCost,
            'description' => $desc,
            'payment_method' => $paymentMethod
        ]);

        // 3. Return updated row
        $rowHtml = $this->view->fetch('inventory/row.twig', ['item' => $updatedItem]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="outerHTML:#inventory-row-' . $itemId . '" id=', $rowHtml);
        
        $response->getBody()->write($rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => "Successfully received {$qtyReceived} of {$item['name']}!"]
                        ]));
    }
}
