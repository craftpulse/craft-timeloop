/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * Control-panel field component. Extends Garnish.Base, is instantiated once per
 * field input with its namespaced container id (so it initializes correctly for
 * Matrix/Neo blocks injected after page load), and:
 *
 *   - toggles the frequency-dependent controls (weekly by-day vs monthly
 *     by-position) and the end-condition controls (never / on date / after N);
 *   - toggles the holiday detail controls with the holiday lightswitch;
 *   - adds and removes exclusion (EXDATE) and extra (RDATE) date rows;
 *   - switches an advanced (non-representable) rule into the simple editor;
 *   - refreshes the live localized summary from the timeloop/summary action,
 *     debounced, reusing the server's own normalization so the preview matches
 *     what will be saved.
 *
 * Everything is scoped to the namespaced container; no global selectors. All
 * listeners go through Garnish's addListener so destroy() (via base()) removes
 * them when a Matrix/Neo block is detached.
 *
 * @author    CraftPulse
 * @copyright Copyright (c) 2025 CraftPulse
 * @since     5.1.0
 */

(function ($) {
  'use strict';

  if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
    return;
  }

  Craft.TimeloopField = Garnish.Base.extend(
    {
      $container: null,
      $mode: null,
      $rrule: null,
      $frequency: null,
      $holidaysEnabled: null,
      $summary: null,
      summaryTimeout: null,

      init: function (id, settings) {
        this.$container = $('#' + id);

        if (!this.$container.length) {
          return;
        }

        this.setSettings(settings, Craft.TimeloopField.defaults);

        // The hidden mode/rrule inputs are rendered directly, so the data hook
        // is reliable. The frequency select and holiday lightswitch go through
        // richer macros, so target their value-bearing input by name instead.
        this.$mode = this.$container.find('[data-timeloop="mode"]');
        this.$rrule = this.$container.find('[data-timeloop="rrule"]');
        this.$frequency = this.$container.find('select[name$="[frequency]"]');
        this.$holidaysEnabled = this.$container.find('input[name$="[holidaysEnabled]"]');
        this.$summary = this.$container.find('[data-timeloop-summary]');

        this.addListener(this.$container, 'change', 'onChange');
        this.addListener(this.$container, 'input', 'onChange');
        this.addListener(
          this.$container.find('[data-timeloop-action="add-row"]'),
          'activate',
          'onAddRow'
        );
        this.addListener(
          this.$container.find('[data-timeloop-action="edit-simple"]'),
          'activate',
          'onEditSimple'
        );

        var self = this;
        this.$container.find('[data-timeloop-action="remove-row"]').each(function () {
          self.addListener($(this), 'activate', 'onRemoveRow');
        });

        this.refreshVisibility();
        this.refreshSummary();
      },

      onChange: function () {
        this.refreshVisibility();
        this.queueSummary();
      },

      refreshVisibility: function () {
        var frequency = this.$frequency.val();
        this.toggleRegion('weekly', frequency === 'WEEKLY');
        this.toggleRegion('monthly', frequency === 'MONTHLY');

        var endCondition = this.$container
          .find('input[name$="[endCondition]"]:checked')
          .val();
        this.toggleRegion('until', endCondition === 'until');
        this.toggleRegion('count', endCondition === 'count');

        this.toggleRegion('holidays-detail', this.holidaysEnabled());
      },

      holidaysEnabled: function () {
        if (this.$holidaysEnabled.is(':checkbox')) {
          return this.$holidaysEnabled.is(':checked');
        }

        return this.$holidaysEnabled.val() === '1';
      },

      toggleRegion: function (name, show) {
        this.$container
          .find('[data-timeloop-region="' + name + '"]')
          .toggleClass('hidden', !show);
      },

      onAddRow: function (ev) {
        var target = $(ev.currentTarget).attr('data-timeloop-target');
        var $list = this.$container.find('[data-timeloop-region="' + target + '"]');
        var fieldName = this.baseName() + '[' + target + '][]';
        var $row = $(
          '<div class="timeloop-daterow">' +
            '<input type="date" class="text" name="' + fieldName + '">' +
            '<button type="button" class="delete icon" data-timeloop-action="remove-row"></button>' +
            '</div>'
        );

        $list.append($row);
        this.addListener(
          $row.find('[data-timeloop-action="remove-row"]'),
          'activate',
          'onRemoveRow'
        );
        $row.find('input').trigger('focus');
        this.queueSummary();
      },

      onRemoveRow: function (ev) {
        $(ev.currentTarget).closest('.timeloop-daterow').remove();
        this.queueSummary();
      },

      onEditSimple: function () {
        this.$mode.val('simple');
        this.$rrule.val('');
        this.toggleRegion('advanced', false);
        this.toggleRegion('simple', true);
        this.refreshVisibility();
        this.queueSummary();
      },

      queueSummary: function () {
        if (this.summaryTimeout) {
          clearTimeout(this.summaryTimeout);
        }

        var self = this;
        this.summaryTimeout = setTimeout(function () {
          self.refreshSummary();
        }, 400);
      },

      refreshSummary: function () {
        if (!this.settings.summaryAction || !this.$summary.length) {
          return;
        }

        var self = this;
        Craft.sendActionRequest('POST', this.settings.summaryAction, {
          data: this.serializeInput(),
        })
          .then(function (response) {
            var summary = response.data && response.data.summary;
            self.$summary.text(summary ? summary : '');
          })
          .catch(function () {
            self.$summary.text('');
          });
      },

      serializeInput: function () {
        var base = this.baseName();
        var params = new URLSearchParams();

        $.each(this.$container.find('input, select, textarea').serializeArray(), function () {
          var name = this.name;

          if (name.indexOf(base) === 0) {
            name = 'input' + name.slice(base.length);
          }

          params.append(name, this.value);
        });

        params.append('locale', this.settings.locale || '');

        return params;
      },

      baseName: function () {
        return this.$mode.attr('name').replace(/\[mode\]$/, '');
      },

      destroy: function () {
        if (this.summaryTimeout) {
          clearTimeout(this.summaryTimeout);
        }

        this.base();
      },
    },
    {
      defaults: {
        summaryAction: null,
        locale: null,
      },
    }
  );
})(jQuery);
