<?php

/**
 * Shared helper for task lists: after a task is created, a banner is shown at
 * the top of the task list announcing the new task. The banner fades away
 * after a few seconds or when clicked.
 *
 * Call flash_new_task_banner() right before redirecting back to a task list,
 * and render_new_task_banner() at the top of the list page.
 */

if (!function_exists('flash_new_task_banner')) {
    function flash_new_task_banner(string $title = ''): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $title = trim(preg_replace('/\s+/', ' ', strip_tags($title)) ?? '');
        if (function_exists('mb_strlen') && mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 80) . '…';
        } elseif (!function_exists('mb_strlen') && strlen($title) > 80) {
            $title = substr($title, 0, 80) . '…';
        }
        $_SESSION['new_task_banner'] = $title;
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

        $title = (string)$_SESSION['new_task_banner'];
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

          function dismiss() {
            if (banner.classList.contains('is-hidden')) return;
            banner.classList.add('is-hidden');
            removeTimer = window.setTimeout(function () {
              if (banner.parentNode) {
                banner.parentNode.removeChild(banner);
              }
            }, 500);
          }

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
