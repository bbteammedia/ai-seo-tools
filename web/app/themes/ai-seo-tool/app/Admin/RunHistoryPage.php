<?php
namespace BBSEO\Admin;

use BBSEO\Helpers\Storage;

class RunHistoryPage
{
    public static function register_menu()
    {
        add_submenu_page(
            'ai-seo-dashboard',
            'Run History',
            'Run History',
            'manage_options',
            'ai-seo-run-history',
            [self::class, 'render_history']
        );
    }

    public static function render_history()
    {
        if (!current_user_can('manage_options')) return;
        $project = isset($_GET['project']) ? sanitize_title(wp_unslash($_GET['project'])) : '';
        if (!$project) {
            echo '<div class="wrap"><h1>Run History</h1><p>No project selected. <a href="' . esc_url(admin_url('admin.php?page=ai-seo-dashboard')) . '">Go back to dashboard</a></p></div>';
            return;
        }

        $pdir = Storage::projectDir($project);
        $rdir = $pdir . '/runs';
        if (!is_dir($rdir)) {
            echo '<div class="wrap"><h1>Run History – ' . esc_html($project) . '</h1><p>No runs found.</p></div>';
            return;
        }

        $runs = [];
        foreach (glob($rdir . '/*', GLOB_ONLYDIR) as $runPath) {
            $runId = basename($runPath);
            $meta = self::read_json($runPath . '/meta.json');
            $sum  = self::read_json($runPath . '/summary.json');

            $pages  = is_array($sum) ? intval($sum['pages'] ?? 0) : count(glob($runPath . '/pages/*.json'));
            $status = is_array($sum) ? ($sum['status'] ?? []) : [];
            $issues = is_array($sum) ? intval($sum['issues']['total'] ?? 0) : 0;
            $started = $meta['started_at'] ?? gmdate('c', filemtime($runPath));

            $runs[] = [
                'run' => $runId,
                'started' => $started,
                'pages' => $pages,
                's2' => intval($status['2xx'] ?? 0),
                's3' => intval($status['3xx'] ?? 0),
                's4' => intval($status['4xx'] ?? 0),
                's5' => intval($status['5xx'] ?? 0),
                'issues' => $issues,
            ];
        }

        usort($runs, fn($a,$b) => strcmp($b['started'], $a['started']));

        echo '<div class="wrap">';
        echo '<h1>Run History – ' . esc_html($project) . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=ai-seo-dashboard')) . '">&larr; Back to Dashboard</a></p>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Run ID</th><th>Started</th><th>Pages</th><th>2xx</th><th>3xx</th><th>4xx</th><th>5xx</th><th>Issues</th></tr></thead><tbody>';

        foreach ($runs as $r) {
            // Use the same report URL structure as the dashboard
            $home = trailingslashit(home_url());
            $repUrl = $home . 'ai-seo-report/' . $project;
            $repUrl = add_query_arg('run', $r['run'], $repUrl);
            $pdfUrl = add_query_arg(['page'=>'ai-seo-export-pdf','project'=>$project,'run'=>$r['run']], admin_url('admin.php'));
            echo '<tr>';
            echo '<td><code>' . esc_html($r['run']) . '</code></td>';
            echo '<td>' . esc_html(date('Y-m-d H:i:s', strtotime($r['started']))) . '</td>';
            echo '<td>' . esc_html($r['pages']) . '</td>';
            echo '<td>' . esc_html($r['s2']) . '</td>';
            echo '<td>' . esc_html($r['s3']) . '</td>';
            echo '<td>' . esc_html($r['s4']) . '</td>';
            echo '<td>' . esc_html($r['s5']) . '</td>';
            echo '<td>' . esc_html($r['issues']) . '</td>';
            echo '</tr>';
        }
        if (!$runs) echo '<tr><td colspan="9"><em>No runs found.</em></td></tr>';
        echo '</tbody></table></div>';
    }

    private static function read_json(string $path)
    {
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }
}