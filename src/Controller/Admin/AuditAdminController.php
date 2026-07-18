<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Repository\AuditEventRepository;
use App\Repository\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditAdminController extends AbstractController
{
    #[Route('/admin/audit', name: 'app_admin_audit_index', methods: ['GET'])]
    public function index(Request $request, AuditEventRepository $audits, ClientRepository $clients): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 50;
        $action = trim($request->query->getString('action'));
        $clientPublicId = trim($request->query->getString('client'));
        $client = $clientPublicId !== '' ? $clients->findOneBy(['publicId' => $clientPublicId]) : null;
        if ($clientPublicId !== '' && !$client instanceof Client) {
            throw $this->createNotFoundException();
        }

        $qb = $audits->createQueryBuilder('event')
            ->leftJoin('event.client', 'client')->addSelect('client')
            ->leftJoin('event.actor', 'actor')->addSelect('actor');
        if ($client instanceof Client) {
            $qb->andWhere('event.client = :client')->setParameter('client', $client);
        }
        if ($action !== '') {
            $qb->andWhere('event.action = :action')->setParameter('action', $action);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(event.id)')->getQuery()->getSingleScalarResult();
        $events = $qb->orderBy('event.occurredAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $actionRows = $audits->createQueryBuilder('event')
            ->select('DISTINCT event.action AS action')
            ->orderBy('event.action', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $this->render('admin/audit/index.html.twig', [
            'events' => $events,
            'clients' => $clients->findBy([], ['name' => 'ASC']),
            'actions' => array_column($actionRows, 'action'),
            'filters' => ['client' => $clientPublicId, 'action' => $action],
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
        ]);
    }
}
