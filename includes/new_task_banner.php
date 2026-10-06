<?php

/**
 * Shared helper for task lists: after a task is created, a banner is shown at
 * the top of the task list announcing the new task. The banner fades away
 * after a few seconds or when clicked, and plays a short chime unless the
 * visitor prefers reduced motion.
 *
 * Manual creates use the session flash (flash_new_task_banner()). The
 * stateless JSON API cannot use the session, so it sets the short-lived
 * NEW_TASK_BANNER_COOKIE instead (new_task_banner_cookie()); the list page
 * consumes and clears it on the next load.
 */

if (!defined('NEW_TASK_BANNER_COOKIE')) {
    define('NEW_TASK_BANNER_COOKIE', 'new_task_banner');
}
if (!defined('NEW_TASK_BANNER_COOKIE_MAX_AGE')) {
    define('NEW_TASK_BANNER_COOKIE_MAX_AGE', 300);
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

if (!function_exists('new_task_banner_cookie')) {
    /**
     * Set the short-lived banner cookie. Used by stateless contexts such as the
     * JSON API, where no session is available. Readable by JavaScript so the
     * list page can clear it after the banner has been shown.
     */
    function new_task_banner_cookie(string $title = ''): bool
    {
        if (headers_sent()) {
            return false;
        }
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

        return setcookie(NEW_TASK_BANNER_COOKIE, new_task_banner_sanitize_title($title), [
            'expires' => time() + NEW_TASK_BANNER_COOKIE_MAX_AGE,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('render_new_task_banner')) {
    function render_new_task_banner(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $title = null;
        $from_cookie = false;

        if (array_key_exists('new_task_banner', $_SESSION)) {
            $title = new_task_banner_sanitize_title((string)$_SESSION['new_task_banner']);
            unset($_SESSION['new_task_banner']);
        } elseif (array_key_exists(NEW_TASK_BANNER_COOKIE, $_COOKIE)) {
            $title = new_task_banner_sanitize_title((string)$_COOKIE[NEW_TASK_BANNER_COOKIE]);
            $from_cookie = true;
        }

        if ($title === null) {
            return;
        }

        if ($from_cookie) {
            unset($_COOKIE[NEW_TASK_BANNER_COOKIE]);
            if (!headers_sent()) {
                setcookie(NEW_TASK_BANNER_COOKIE, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
        }

        $message = $title === '' ? 'New task added' : 'New task added: ' . $title;
        ?>
        <div class="new-task-banner" id="new-task-banner" role="status" aria-live="polite" title="Click to dismiss"
             data-clear-cookie="<?= $from_cookie ? '1' : '0' ?>">
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

          var cookieName = <?= json_encode(NEW_TASK_BANNER_COOKIE) ?>;
          var removeTimer = null;

          if (banner.dataset.clearCookie === '1') {
            document.cookie = cookieName + '=; Max-Age=0; path=/; SameSite=Lax';
          }

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
