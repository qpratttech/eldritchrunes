<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class RulesController extends Controller
{
    public function __invoke()
    {
        $path = storage_path('app/rules/core.md');
        $markdown = file_exists($path) ? file_get_contents($path) : '# Rules unavailable';
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $sections = [];
        $current = ['title' => 'Eldritch Runes 2E', 'body' => ''];
        foreach (preg_split("/\r\n|\n|\r/", $markdown) as $line) {
            if (str_starts_with($line, '## ')) {
                $sections[] = $current;
                $current = ['title' => $this->plain(trim(substr($line, 3))), 'body' => ''];
            } else {
                $current['body'] .= $line."\n";
            }
        }
        $sections[] = $current;
        $sections = array_values(array_filter($sections, fn (array $section) => trim($section['body']) !== ''));
        $entries = [];
        $sections = array_map(function (array $section) use ($converter, &$entries) {
            $body = preg_replace('/^#\s+'.preg_quote($section['title'], '/').'\s*\n+/', '', $section['body']) ?? $section['body'];
            $section['id'] = $this->slug($section['title']);
            $html = trim($body) === '' ? '' : $converter->convert($body)->getContent();
            $section['html'] = $this->anchorHeadings($html, $section['id']);
            foreach ($this->entries($body, $section['title'], $section['id'], $converter) as $entry) {
                $entries[] = $entry;
            }
            unset($section['body']);

            return $section;
        }, $sections);

        return Inertia::render('Rules/Show', [
            'sections' => $sections,
            'entries' => $entries,
        ]);
    }

    /**
     * @return array<int, array{id: string, chapter: string, chapterId: string, title: string, text: string}>
     */
    private function entries(string $body, string $chapter, string $chapterId, GithubFlavoredMarkdownConverter $converter): array
    {
        $entries = [];
        $heading = $chapter;
        $headingId = $chapterId;
        $buffer = '';
        $flush = function () use (&$entries, &$buffer, &$heading, &$headingId, $chapter, $chapterId, $converter) {
            $text = $this->plainText(trim($buffer) === '' ? '' : $converter->convert($buffer)->getContent());
            if ($text === '' || strcasecmp($heading, 'Contents') === 0) {
                return;
            }
            $entries[] = [
                'id' => $headingId,
                'chapter' => $chapter,
                'chapterId' => $chapterId,
                'title' => $heading,
                'text' => $text,
            ];
        };
        foreach (preg_split("/\r\n|\n|\r/", $body) as $line) {
            if (preg_match('/^(#{3,4})\s+(.+)$/', $line, $matches)) {
                $flush();
                $buffer = '';
                $heading = $this->plain(trim($matches[2]));
                $headingId = $chapterId.'-'.$this->slug($heading);

                continue;
            }
            $buffer .= $line."\n";
        }
        $flush();

        return $entries;
    }

    private function anchorHeadings(string $html, string $chapterId): string
    {
        return preg_replace_callback('/<h([34])>(.*?)<\/h\1>/s', function (array $matches) use ($chapterId) {
            $title = trim(html_entity_decode(strip_tags($matches[2])));
            $id = $chapterId.'-'.$this->slug($title);

            return '<h'.$matches[1].' id="'.e($id).'">'.$matches[2].'</h'.$matches[1].'>';
        }, $html) ?? $html;
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html));
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function plain(string $title): string
    {
        return str_replace('\\', '', $title);
    }

    private function slug(string $title): string
    {
        $slug = strtolower($this->plain($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
