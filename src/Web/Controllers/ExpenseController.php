<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ExpenseRepository;
use App\Repositories\ExpenseCategoryRepository;

class ExpenseController
{
    private Twig $view;
    private ExpenseRepository $expenseRepo;
    private ExpenseCategoryRepository $expenseCategoryRepo;

    public function __construct(Twig $view, ExpenseRepository $expenseRepo, ExpenseCategoryRepository $expenseCategoryRepo)
    {
        $this->view = $view;
        $this->expenseRepo = $expenseRepo;
        $this->expenseCategoryRepo = $expenseCategoryRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseRepo->setTenantId($tenantId);

        $queryParams = $request->getQueryParams();
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        $limit = 20;

        $startDate = $queryParams['start_date'] ?? date('Y-m-01');
        $endDate = $queryParams['end_date'] ?? date('Y-m-t');
        $category = $queryParams['category'] ?? '';

        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        if (!empty($category)) {
            $filters['category'] = $category;
        }

        $result = $this->expenseRepo->getPaginatedExpenses([
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters
        ]);

        $totalExpenses = $this->expenseRepo->getTotalExpensesByDateRange($startDate, $endDate);

        $this->expenseCategoryRepo->setTenantId($tenantId);
        $categories = $this->expenseCategoryRepo->getAll();

        return $this->view->render($response, 'expenses/index.twig', [
            'title' => 'Expenses Management',
            'active_menu' => 'expenses',
            'expenses' => $result['data'],
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total' => $result['total']
            ],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'selected_category' => $category,
            'categories' => $categories,
            'total_expenses' => $totalExpenses
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseRepo->setTenantId($tenantId);

        $data = $request->getParsedBody();
        
        $this->expenseRepo->create([
            'expense_date' => $data['expense_date'] ?? date('Y-m-d'),
            'category' => $data['category'],
            'amount' => (float)($data['amount'] ?? 0),
            'description' => $data['description'] ?? '',
            'payment_method' => $data['payment_method'] ?? ''
        ]);

        return $response->withHeader('Location', '/web/expenses')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseRepo->setTenantId($tenantId);
        
        $id = (int)$args['id'];
        $expense = $this->expenseRepo->getById($id);
        
        if (!$expense) {
            return $response->withHeader('Location', '/web/expenses')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseCategoryRepo->setTenantId($tenantId);
        $categories = $this->expenseCategoryRepo->getAll();

        return $this->view->render($response, 'expenses/form.twig', [
            'title' => 'Edit Expense',
            'active_menu' => 'expenses',
            'expense' => $expense,
            'categories' => $categories
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseRepo->setTenantId($tenantId);
        
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $this->expenseRepo->update($id, [
            'expense_date' => $data['expense_date'],
            'category' => $data['category'],
            'amount' => (float)($data['amount']),
            'description' => $data['description'] ?? '',
            'payment_method' => $data['payment_method'] ?? ''
        ]);

        return $response->withHeader('Location', '/web/expenses')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseRepo->setTenantId($tenantId);
        
        $id = (int)$args['id'];
        $this->expenseRepo->delete($id);

        return $response->withHeader('Location', '/web/expenses')->withStatus(302);
    }

    // --- Expense Categories CRUD ---

    public function categories(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('role');
        if (!in_array($role, ['admin', 'cashier'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseCategoryRepo->setTenantId($tenantId);
        
        $categories = $this->expenseCategoryRepo->getAll();

        return $this->view->render($response, 'expenses/categories.twig', [
            'title' => 'Expense Categories',
            'active_menu' => 'expenses',
            'categories' => $categories
        ]);
    }

    public function createCategory(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('role');
        if (!in_array($role, ['admin', 'cashier'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        return $this->view->render($response, 'expenses/category_form.twig', [
            'title' => 'New Expense Category',
            'active_menu' => 'expenses'
        ]);
    }

    public function storeCategory(Request $request, Response $response): Response
    {
        $role = $request->getAttribute('role');
        if (!in_array($role, ['admin', 'cashier'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseCategoryRepo->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $this->expenseCategoryRepo->create(['name' => $data['name']]);

        return $response->withHeader('Location', '/web/expenses/categories')->withStatus(302);
    }

    public function editCategory(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if (!in_array($role, ['admin', 'cashier'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseCategoryRepo->setTenantId($tenantId);
        
        $id = (int)$args['id'];
        $category = $this->expenseCategoryRepo->getById($id);

        if (!$category) {
            return $response->withHeader('Location', '/web/expenses/categories')->withStatus(302);
        }

        return $this->view->render($response, 'expenses/category_form.twig', [
            'title' => 'Edit Expense Category',
            'active_menu' => 'expenses',
            'category' => $category
        ]);
    }

    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if (!in_array($role, ['admin', 'cashier'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseCategoryRepo->setTenantId($tenantId);
        
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $this->expenseCategoryRepo->update($id, ['name' => $data['name']]);

        return $response->withHeader('Location', '/web/expenses/categories')->withStatus(302);
    }

    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if (!in_array($role, ['admin', 'cashier'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->expenseCategoryRepo->setTenantId($tenantId);
        
        $id = (int)$args['id'];
        $this->expenseCategoryRepo->delete($id);

        return $response->withHeader('Location', '/web/expenses/categories')->withStatus(302);
    }
}
