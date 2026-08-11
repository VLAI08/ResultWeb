<?php

namespace App\Controller\Api;

use App\Service\DomainsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/domains')]
class DomainApiController extends ApiBaseController
{
    public function __construct(private DomainsService $domains)
    {
    }

    #[Route('', name: 'api_domains_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->has('recordPerPage')
            ? $request->query->getInt('recordPerPage', 10)
            : $request->query->getInt('limit', 20);
        $data = $this->domains->findAll(
            $page,
            $limit,
            (string) $request->query->get('parameter', ''),
            (string) $request->query->get('name', ''),
            $request->query->has('active') ? (bool) $request->query->get('active') : null
        );
        return $this->json(['content' => $data['items'], 'totalRecord' => $data['total_count']]);
    }

    #[Route('/by-name/{name}', name: 'api_domains_by_name', methods: ['GET'])]
    public function byName(Request $request, string $name): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        return $this->json($this->domains->findActivesByName($name));
    }

    #[Route('/{id}', name: 'api_domains_get', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $domain = $this->domains->find($id);
        if (!$domain) {
            return $this->json(['message' => 'Dominio no encontrado'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($domain);
    }

    #[Route('', name: 'api_domains_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        $domain = $this->domains->create($data);
        return $this->json($domain, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_domains_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        $domain = $this->domains->update($id, $data);
        if (!$domain) {
            return $this->json(['message' => 'Dominio no encontrado'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($domain);
    }

    #[Route('/{id}', name: 'api_domains_delete', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $this->domains->deactivate($id);
        return $this->json(['success' => true]);
    }
}
