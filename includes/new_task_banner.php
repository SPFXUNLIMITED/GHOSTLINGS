<?php

/**
 * Shared helpers for task notifications.
 *
 * Standalone tasks are announced to *every* viewer of the task list: each
 * insert (web form or JSON API) records the new task id in the app_state
 * table via record_last_new_task_id(), and standalone_tasks.php polls
 * standalone_task_latest.php for changes.
 *
 * The other task lists (tasks.php, playbook_tasks.php) still use the session
 * flash helpers below for their own creates.
 */

if (!defined('LAST_NEW_TASK_STATE_KEY')) {
    define('LAST_NEW_TASK_STATE_KEY', 'last_new_task_id');
}

if (!function_exists('new_task_banner_sanitize_title')) {
    function new_task_banner_sanitize_title(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', strip_tags($title)) ?? '');
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($title) > 80 ? mb_substr($title, 0, 80) . '…' : $title;
        }
        return strlen($title) > 80 ? substr($title, 0, 80) . '…' : $title;
    }
}

if (!function_exists('flash_new_task_banner')) {
    function flash_new_task_banner(string $title = ''): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['new_task_banner'] = new_task_banner_sanitize_title($title);
    }
}

if (!function_exists('app_ensure_state_table')) {
    function app_ensure_state_table(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_state ('
            . ' state_key VARCHAR(64) NOT NULL,'
            . ' state_val VARCHAR(255) NULL,'
            . ' updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (state_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $ready = true;
    }
}

if (!function_exists('record_last_new_task_id')) {
    /**
     * Remember the id of the task that was just created so that every task
     * list viewer can be notified about it, not only the creator.
     */
    function record_last_new_task_id(PDO $pdo, int $task_id): void
    {
        if ($task_id <= 0) {
            return;
        }

        try {
            app_ensure_state_table($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO app_state (state_key, state_val) VALUES (?, ?)'
                . ' ON DUPLICATE KEY UPDATE state_val = VALUES(state_val)'
            );
            $stmt->execute([LAST_NEW_TASK_STATE_KEY, (string)$task_id]);
        } catch (Throwable $e) {
            // The notification is a nicety; never fail the create because of it.
            error_log('record_last_new_task_id: ' . $e->getMessage());
        }
    }
}

if (!function_exists('get_last_new_task_id')) {
    function get_last_new_task_id(PDO $pdo): int
    {
        try {
            app_ensure_state_table($pdo);
            $stmt = $pdo->prepare('SELECT state_val FROM app_state WHERE state_key = ?');
            $stmt->execute([LAST_NEW_TASK_STATE_KEY]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('get_last_new_task_id: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('render_new_task_banner')) {
    function render_new_task_banner(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!array_key_exists('new_task_banner', $_SESSION)) {
            return;
        }

        $title = new_task_banner_sanitize_title((string)$_SESSION['new_task_banner']);
        unset($_SESSION['new_task_banner']);

        $message = $title === '' ? 'New task added' : 'New task added: ' . $title;
        ?>
        <div class="new-task-banner" id="new-task-banner" role="status" aria-live="polite" title="Click to dismiss">
          <span class="new-task-banner-dot" aria-hidden="true"></span>
          <span class="new-task-banner-text"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <style>
        .new-task-banner {
          position:fixed; top:16px; left:50%; transform:translateX(-50%);
          z-index:9600; display:flex; align-items:center; gap:10px;
          max-width:min(620px, calc(100vw - 32px));
          padding:10px 18px; border-radius:999px;
          background:#dcfce7; color:#166534; border:1px solid #86efac;
          box-shadow:0 10px 30px rgba(15,23,42,.18);
          font-size:14px; font-weight:600; cursor:pointer;
          opacity:1; transition:opacity .45s ease;
        }
        .new-task-banner.is-hidden { opacity:0; pointer-events:none; }
        .new-task-banner-dot { width:8px; height:8px; border-radius:999px; background:#16a34a; flex:none; }
        .new-task-banner-text { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        @media (prefers-reduced-motion: reduce) {
          .new-task-banner { transition:none; }
        }
        </style>
        <script>
        (function () {
          'use strict';

          var banner = document.getElementById('new-task-banner');
          if (!banner) return;

          var removeTimer = null;

          function prefersReducedMotion() {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
          }

          function playChime() {
            if (prefersReducedMotion()) return;

            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;

            try {
              var ctx = new Ctx();
              var now = ctx.currentTime;
              var gain = ctx.createGain();
              gain.gain.setValueAtTime(0.0001, now);
              gain.gain.exponentialRampToValueAtTime(0.09, now + 0.04);
              gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.55);
              gain.connect(ctx.destination);

              [880, 1318.5].forEach(function (frequency, index) {
                var osc = ctx.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(frequency, now + index * 0.09);
                osc.connect(gain);
                osc.start(now + index * 0.09);
                osc.stop(now + 0.6);
              });

              window.setTimeout(function () {
                if (ctx.close) ctx.close();
              }, 900);
            } catch (e) {
              /* Audio is a nicety; ignore failures (e.g. autoplay policies). */
            }
          }

          function dismiss() {
            if (banner.classList.contains('is-hidden')) return;
            banner.classList.add('is-hidden');
            removeTimer = window.setTimeout(function () {
              if (banner.parentNode) {
                banner.parentNode.removeChild(banner);
              }
            }, 500);
          }

          playChime();
          banner.addEventListener('click', dismiss);
          window.setTimeout(dismiss, 5000);

          window.addEventListener('pagehide', function () {
            if (removeTimer) window.clearTimeout(removeTimer);
          });
        })();
        </script>
        <?php
    }
}
