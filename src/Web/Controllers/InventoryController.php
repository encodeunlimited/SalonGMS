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
    private \PDO $db;

    public function __construct(Twig $view, InventoryRepository $inventory, \App\Repositories\ExpenseRepository $expenseRepo, \PDO $db)
    {
        $this->view = $view;
        $this->inventory = $inventory;
        $this->expenseRepo = $expenseRepo;
        $this->db = $db;
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
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        return $this->view->render($response, 'inventory/modal.twig');
    }

    public function store(Request $request, Response $response): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        
        $item = $this->inventory->create([
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => 0, // Initial stock is 0
            'price' => 0.00,
            'low_stock_limit' => (int)($data['low_stock_limit'] ?? 5)
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
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
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
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
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
            'price' => $existingItem['price'],
            'expiry_date' => $existingItem['expiry_date'],
            'low_stock_limit' => (int)($data['low_stock_limit'] ?? 5)
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
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
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

    public function batchGrnForm(Request $request, Response $response): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }

        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $items = $this->inventory->getAll();
        
        $html = $this->view->fetch('inventory/grn_modal.twig', [
            'inventory_items' => $items
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function processBatchGrn(Request $request, Response $response): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }

        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        $this->expenseRepo->setTenantId($tenantId);
        $userId = $request->getAttribute('user_id');
        
        $data = $request->getParsedBody();
        $referenceNo = $data['reference_no'] ?? '';
        $paymentMethod = $data['payment_method'] ?? 'Cash';
        $items = $data['items'] ?? [];
        $totalCost = (float)($data['total_cost'] ?? 0);

        if (empty($items)) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'No items added!', 'type' => 'error']
                            ]));
        }

        $this->db->beginTransaction();
        try {
            // 1. Log Expense
            $desc = "Batch GRN: " . count($items) . " items. Ref: {$referenceNo}";
            $this->expenseRepo->create([
                'expense_date' => date('Y-m-d'),
                'category' => 'Inventory Purchase',
                'amount' => $totalCost,
                'description' => $desc,
                'payment_method' => $paymentMethod
            ]);

            $stmtTx = $this->db->prepare("INSERT INTO inventory_transactions (tenant_id, type, reference_no, item_id, quantity, unit_cost, total_cost, expiry_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            // 2. Process each item
            foreach ($items as $itemData) {
                $itemId = (int)$itemData['id'];
                $qty = (int)$itemData['quantity'];
                $unitCost = (float)$itemData['unit_cost'];
                $expiry = !empty($itemData['expiry_date']) ? $itemData['expiry_date'] : null;

                $existingItem = $this->inventory->getById($itemId);
                if ($existingItem) {
                    // Update Stock & Price & Expiry
                    $newQty = $existingItem['quantity'] + $qty;
                    $this->inventory->update($itemId, [
                        'name' => $existingItem['name'],
                        'sku' => $existingItem['sku'],
                        'description' => $existingItem['description'],
                        'quantity' => $newQty,
                        'price' => $unitCost, // Using unit cost as the new price
                        'expiry_date' => $expiry
                    ]);

                    // Log Transaction
                    $stmtTx->execute([
                        $tenantId, 'GRN', $referenceNo, $itemId, $qty, $unitCost, ($qty * $unitCost), $expiry, $userId
                    ]);
                }
            }

            $this->db->commit();

            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Batch GRN processed successfully!']
                            ]));

        } catch (\Exception $e) {
            $this->db->rollBack();
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Error: ' . $e->getMessage(), 'type' => 'error']
                            ]));
        }
    }

    public function batchIssueForm(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        
        $items = $this->inventory->getAll();
        
        $html = $this->view->fetch('inventory/issue_modal.twig', [
            'inventory_items' => $items
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function processBatchIssue(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->inventory->setTenantId($tenantId);
        $userId = $request->getAttribute('user_id');
        
        $data = $request->getParsedBody();
        $notes = $data['notes'] ?? '';
        $items = $data['items'] ?? [];

        if (empty($items)) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'No items added!', 'type' => 'error']
                            ]));
        }

        $this->db->beginTransaction();
        try {
            $stmtTx = $this->db->prepare("INSERT INTO inventory_transactions (tenant_id, type, reference_no, item_id, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $refNo = 'ISS-' . date('Ymd-His');

            // Process each item
            foreach ($items as $itemData) {
                $itemId = (int)$itemData['id'];
                $qty = (int)$itemData['quantity'];

                $existingItem = $this->inventory->getById($itemId);
                if ($existingItem && $existingItem['quantity'] >= $qty) {
                    // Deduct Stock
                    $newQty = $existingItem['quantity'] - $qty;
                    $this->inventory->update($itemId, [
                        'name' => $existingItem['name'],
                        'sku' => $existingItem['sku'],
                        'description' => $existingItem['description'],
                        'quantity' => $newQty,
                        'price' => $existingItem['price'],
                        'expiry_date' => $existingItem['expiry_date']
                    ]);

                    // Log Transaction
                    $stmtTx->execute([
                        $tenantId, 'ISSUE', $refNo, $itemId, $qty, $notes, $userId
                    ]);
                } else {
                    throw new \Exception("Insufficient stock for item ID {$itemId}.");
                }
            }

            $this->db->commit();

            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Stock issued successfully!']
                            ]));

        } catch (\Exception $e) {
            $this->db->rollBack();
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Error: ' . $e->getMessage(), 'type' => 'error']
                            ]));
        }
    }
}
