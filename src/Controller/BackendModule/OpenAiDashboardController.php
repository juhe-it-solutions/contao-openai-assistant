<?php

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller\BackendModule;

use Contao\CoreBundle\Controller\AbstractController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsBackendModule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[AsBackendModule(
    category: 'ai_tools',
    name: 'openai_dashboard',
    title: 'OpenAI Dashboard',
    description: 'Manage OpenAI configurations, files, and assistants'
)]
class OpenAiDashboardController extends AbstractController
{
    #[Route('/contao/openai-dashboard', name: 'openai_dashboard')]
    public function index(): Response
    {
        return $this->render('@Contao/be_main.html.twig', [
            'title' => 'OpenAI Dashboard',
            'headline' => 'OpenAI Assistant Management',
            'content' => 'Manage your OpenAI configurations, files, and assistants from this dashboard.'
        ]);
    }
} 