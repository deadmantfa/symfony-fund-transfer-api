<?php

declare(strict_types=1);

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ReportController
{
    public function __construct(private string $projectDir)
    {
    }

    #[Route('/reports/query-plans', methods: ['GET'])]
    public function queryPlans(): Response
    {
        $file = $this->projectDir . '/docs/reports/query-plans.md';
        $markdown = is_file($file) ? (string) file_get_contents($file) : "# Query Plans\n\nRun `make explain` to generate this report.";

        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $body = $converter->convert($markdown)->getContent();

        return new Response($this->wrap('Query Execution Plans', $body), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function wrap(string $title, string $body): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} — Fund Transfer API</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #0f1117; color: #e2e8f0;
    min-height: 100vh; padding: 2rem 1rem;
    line-height: 1.7;
  }
  .container { max-width: 820px; margin: 0 auto; }

  nav { margin-bottom: 2rem; }
  nav a {
    color: #6366f1; text-decoration: none; font-size: 0.85rem;
    display: inline-flex; align-items: center; gap: 0.3rem;
  }
  nav a:hover { text-decoration: underline; }

  h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.4rem; }
  h2 { font-size: 1.1rem; font-weight: 600; color: #a5b4fc; margin: 2rem 0 0.6rem; padding-bottom: 0.4rem; border-bottom: 1px solid #2d3348; }
  h3 { font-size: 0.95rem; font-weight: 600; color: #94a3b8; margin: 1.25rem 0 0.4rem; }
  p  { color: #94a3b8; margin: 0.5rem 0; font-size: 0.9rem; }
  blockquote { border-left: 3px solid #2d3348; padding-left: 1rem; color: #64748b; font-size: 0.85rem; margin: 1rem 0; }

  pre {
    background: #12151f; border: 1px solid #2d3348; border-radius: 8px;
    padding: 1rem 1.25rem; overflow-x: auto; margin: 0.75rem 0;
  }
  pre code {
    font-family: "SF Mono", "Fira Code", "Fira Mono", monospace;
    font-size: 0.78rem; color: #a5b4fc; line-height: 1.6;
    background: none; padding: 0;
  }
  code {
    font-family: "SF Mono", "Fira Code", monospace;
    font-size: 0.8rem; color: #a5b4fc;
    background: #12151f; padding: 2px 6px; border-radius: 4px;
  }

  footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid #1e2330; color: #475569; font-size: 0.78rem; text-align: center; }
  footer a { color: #6366f1; text-decoration: none; }
</style>
</head>
<body>
<div class="container">
  <nav><a href="/">← Back to overview</a></nav>
  {$body}
  <footer><a href="/">Home</a> · <a href="/api/docs">API Docs</a> · <a href="/reports/coverage/index.html">Coverage</a></footer>
</div>
</body>
</html>
HTML;
    }
}
