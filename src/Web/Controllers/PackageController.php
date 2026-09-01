<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\PackageRepository;
use App\Repositories\ServiceRepository;

class PackageController
{
    private Twig $view;
    private PackageRepository $packageRepo;
    private ServiceRepository $serviceRepo;

    public function __construct(Twig $view, PackageRepository $packageRepo, ServiceRepository $serviceRepo)
    {
        $this->view = $view;
        $this->packageRepo = $packageRepo;
        $this->serviceRepo = $serviceRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->packageRepo->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $sort   = $params['sort'] ?? 'id';
        $dir    = $params['dir'] ?? 'desc';
        $page   = (int)($params['page'] ?? 1);

        $paginated = $this->packageRepo->getPaginated([
            'search' => $search,
            'sort'   => $sort,
            'dir'    => $dir,
            'page'   => $page,
            'limit'  => 10
        ]);

        return $this->view->render($response, 'packages/index.twig', [
            'title'      => 'Packages',
            'active_menu'=> 'packages',
            'packages'   => $paginated['data'],
            'pagination' => $paginated,
            'search'     => $search,
            'sort'       => $sort,
            'dir'        => $dir
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->serviceRepo->setTenantId($tenantId);

        $services = $this->serviceRepo->getAll();

        return $this->view->render($response, 'packages/form.twig', [
            'title' => 'Create Package',
            'active_menu' => 'packages',
            'services' => $services
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->packageRepo->setTenantId($tenantId);

        $data = (array)$request->getParsedBody();
        
        $this->packageRepo->create([
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'price' => (float)($data['price'] ?? 0),
            'active' => isset($data['active']) ? 1 : 0,
            'validity_months' => $data['validity_months'] ?? null,
            'service_ids' => $data['service_ids'] ?? []
        ]);

        return $response->withHeader('Location', '/web/packages')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->packageRepo->setTenantId($tenantId);
        $this->serviceRepo->setTenantId($tenantId);

        $package = $this->packageRepo->getById((int)$args['id']);
        if (!$package) {
            return $response->withHeader('Location', '/web/packages')->withStatus(302);
        }

        $services = $this->serviceRepo->getAll();

        // Pluck service IDs for the view
        $package['service_ids'] = array_column($package['services'], 'id');

        return $this->view->render($response, 'packages/form.twig', [
            'title' => 'Edit Package',
            'active_menu' => 'packages',
            'package' => $package,
            'services' => $services
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->packageRepo->setTenantId($tenantId);

        $data = (array)$request->getParsedBody();

        $this->packageRepo->update((int)$args['id'], [
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'price' => (float)($data['price'] ?? 0),
            'active' => isset($data['active']) ? 1 : 0,
            'validity_months' => $data['validity_months'] ?? null,
            'service_ids' => $data['service_ids'] ?? []
        ]);

        return $response->withHeader('Location', '/web/packages')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->packageRepo->setTenantId($tenantId);

        $this->packageRepo->delete((int)$args['id']);

        return $response->withHeader('Location', '/web/packages')->withStatus(302);
    }
}
