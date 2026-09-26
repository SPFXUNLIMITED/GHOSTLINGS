<?php

/**
 * Shared helper for task lists: completed tasks are hidden by default and can
 * be revealed on demand with a "Completed" toggle button. Completed tasks are
 * never removed, only hidden in the UI.
 *
 * Mark each completed row with the class "completed-task-row" and give the
 * list container (usually a <tbody>) an id that is passed to
 * render_completed_tasks_toggle().
 */

if (!function_exists('render_completed_tasks_toggle')) {
    function render_completed_tasks_toggle(string $target_id, int $completed_count = 0): void
    {
        static $assets_rendered = false;

        $target_attr = htmlspecialchars($target_id, ENT_QUOTES, 'UTF-8');
        $label = 'Completed' . ($completed_count > 0 ? ' (' . $completed_count . ')' : '');
        ?>
        <button type="button"
                class="btn completed-tasks-toggle"
                data-completed-target="<?= $target_attr ?>"
                aria-expanded="false"
                aria-controls="<?= $target_attr ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
        <?php
        if ($assets_rendered) {
            return;
        }
        $assets_rendered = true;
        ?>
        <style>
        .completed-tasks-hidden .completed-task-row { display:none; }
        .completed-tasks-toggle.is-active { background:#dcfce7; color:#166534; }
        </style>
        <script>
        (function () {
          'use strict';

          function applyState(target, button, showCompleted) {
            target.classList.toggle('completed-tasks-hidden', !showCompleted);
            button.classList.toggle('is-active', showCompleted);
            button.setAttribute('aria-expanded', showCompleted ? 'true' : 'false');
          }

          function init() {
            var buttons = document.querySelectorAll('.completed-tasks-toggle');
            Array.prototype.forEach.call(buttons, function (button) {
              var target = document.getElementById(button.getAttribute('data-completed-target'));
              if (!target) {
                button.hidden = true;
                return;
              }

              if (!target.querySelector('.completed-task-row')) {
                button.hidden = true;
                return;
              }

              applyState(target, button, false);

              button.addEventListener('click', function () {
                applyState(target, button, target.classList.contains('completed-tasks-hidden'));
              });
            });
          }

          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
          } else {
            init();
          }
        })();
        </script>
        <?php
    }
}
