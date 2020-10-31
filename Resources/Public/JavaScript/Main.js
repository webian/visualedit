/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
/**
 * Module: Webian/Visualedit/Main
 * Main logic for resizing the view of the frame
 */
define([
  'jquery',
  'TYPO3/CMS/Backend/Storage/Persistent',
  'jquery-ui/resizable'
], function($, PersistentStorage) {
  'use strict';

  /**
   * @type {{<resizableContainerIdentifier: string, sizeIdentifier: string, moduleBodySelector: string, storagePrefix: string, $iframe: null, $resizableContainer: null, $sizeSelector: null}}
   * @exports Webian/Viewpage/Main
   */
  var VisualEdit = {

    resizableContainerIdentifier: '.t3js-visualedit-resizeable',
    sizeIdentifier: ' .t3js-visualedit-size',
    moduleBodySelector: '.t3js-module-body',

    defaultLabel: 'Custom',
    minimalHeight: 300,
    minimalWidth: 300,

    storagePrefix: 'moduleData.web_view.States.',
    $iframe: null,
    $resizableContainer: null,
    $sizeSelector: null,

    customSelector: '.t3js-preset-custom',

    changeOrientationSelector: '.t3js-change-orientation',
    changePresetSelector: '.t3js-change-preset',

    inputWidthSelector: '.t3js-visualedit-input-width',
    inputHeightSelector: '.t3js-visualedit-input-height',

    currentButtonSelector: '.t3js-preset-current',
    currentLabelSelector: '.t3js-visualedit-current-label',

    queue: [],
    queueIsRunning: false,
    queueDelayTimer: null

  };

  VisualEdit.persistQueue = function() {
    if (VisualEdit.queueIsRunning === false && VisualEdit.queue.length >= 1) {
      VisualEdit.queueIsRunning = true;
      var item = VisualEdit.queue.shift();
      PersistentStorage.set(item.storageIdentifier, item.data).done(function() {
        VisualEdit.queueIsRunning = false;
        VisualEdit.persistQueue();
      });
    }
  }

  VisualEdit.addToQueue = function(storageIdentifier, data) {
    var item = {
      'storageIdentifier': storageIdentifier,
      'data': data
    };
    VisualEdit.queue.push(item);
    if (VisualEdit.queue.length >= 1) {
      VisualEdit.persistQueue();
    }
  }

  VisualEdit.setSize = function(width, height) {
    if (isNaN(width) || isNaN(height)) {
      // Both width and height not set => go full size
      $(VisualEdit.currentButtonSelector).removeData('width');
      $(VisualEdit.currentButtonSelector).removeData('height')

      VisualEdit.$resizableContainer.css({
        width: '100%',
        height: '100%',
        left: 0
      });

      $(VisualEdit.inputWidthSelector).val('');
      $(VisualEdit.inputHeightSelector).val('');
    } else {
      if (isNaN(width)) {
        width = VisualEdit.calculateContainerMaxWidth();
      }
      if (width < VisualEdit.minimalWidth) {
        width = VisualEdit.minimalWidth;
      }
      if (isNaN(height)) {
        height = VisualEdit.calculateContainerMaxHeight();
      }
      if (height < VisualEdit.minimalHeight) {
        height = VisualEdit.minimalHeight;
      }

      $(VisualEdit.currentButtonSelector).data('width', width);
      $(VisualEdit.currentButtonSelector).data('height', height);

      $(VisualEdit.inputWidthSelector).val(width);
      $(VisualEdit.inputHeightSelector).val(height);

      VisualEdit.$resizableContainer.css({
        width: width,
        height: height,
        left: 0
      });
    }
  }

  VisualEdit.getCurrentWidth = function() {
    return $(VisualEdit.inputWidthSelector).val();
  }

  VisualEdit.getCurrentHeight = function() {
    return $(VisualEdit.inputHeightSelector).val();
  }

  VisualEdit.setLabel = function(label) {
    $(VisualEdit.currentButtonSelector).data('label', label);
    $(VisualEdit.currentLabelSelector).html(label);
  }

  VisualEdit.getCurrentLabel = function() {
    return $(VisualEdit.currentLabelSelector).html().trim();
  }

  VisualEdit.persistCurrentPreset = function() {
    var data = {
      width: VisualEdit.getCurrentWidth(),
      height: VisualEdit.getCurrentHeight(),
      label: VisualEdit.getCurrentLabel()
    }
    VisualEdit.addToQueue(VisualEdit.storagePrefix + 'current', data);
  }

  VisualEdit.persistCustomPreset = function() {
    var data = {
      width: VisualEdit.getCurrentWidth(),
      height: VisualEdit.getCurrentHeight()
    }
    $(VisualEdit.customSelector).data("width", data.width);
    $(VisualEdit.customSelector).data("height", data.height);
    
    var newCustomLabel = 'Custom (' + data.width + 'x' + data.height + ')';
    $(VisualEdit.customSelector).attr("title", newCustomLabel);
    $(VisualEdit.customSelector).contents().filter(function(){ return this.nodeType == 3; }).first().replaceWith(newCustomLabel);

    VisualEdit.addToQueue(VisualEdit.storagePrefix + 'custom', data);
  }

  VisualEdit.persistCustomPresetAfterChange = function() {
    clearTimeout(VisualEdit.queueDelayTimer);
    VisualEdit.queueDelayTimer = setTimeout(function() {
      VisualEdit.persistCustomPreset();
      VisualEdit.persistCurrentPreset();
    }, 1000);
  };

  /**
   * Initialize
   */
  VisualEdit.initialize = function() {
    // Mark current preset button (main split btn) label as currentLabelSelector
    $(VisualEdit.currentButtonSelector).contents().eq(2).wrap('<span class="t3js-visualedit-current-label"/>');

    VisualEdit.$iframe = $('#tx_visualedit_iframe');
    VisualEdit.$resizableContainer = $(VisualEdit.resizableContainerIdentifier);
    VisualEdit.$sizeSelector = $(VisualEdit.sizeIdentifier);

    // Set current preset button data with current state
    $(VisualEdit.currentButtonSelector).data('width', $(VisualEdit.inputWidthSelector).val());
    $(VisualEdit.currentButtonSelector).data('height', $(VisualEdit.inputHeightSelector).val());

    // Change orientation
    $(document).on('click', VisualEdit.changeOrientationSelector, function() {
      var width = $(VisualEdit.inputHeightSelector).val();
      var height = $(VisualEdit.inputWidthSelector).val();
      VisualEdit.setSize(width, height);
      VisualEdit.persistCurrentPreset();
    });

    // On change
    $(document).on('change', VisualEdit.inputWidthSelector, function() {
      var width = $(VisualEdit.inputWidthSelector).val();
      var height = $(VisualEdit.inputHeightSelector).val();
      VisualEdit.setSize(width, height);
      VisualEdit.setLabel(VisualEdit.defaultLabel);
      VisualEdit.persistCustomPresetAfterChange();
    });
    $(document).on('change', VisualEdit.inputHeightSelector, function() {
      var width = $(VisualEdit.inputWidthSelector).val();
      var height = $(VisualEdit.inputHeightSelector).val();
      VisualEdit.setSize(width, height);
      VisualEdit.setLabel(VisualEdit.defaultLabel);
      VisualEdit.persistCustomPresetAfterChange();
    });

    // Add event to width selector so the container is resized
    $(document).on('click', VisualEdit.changePresetSelector, function() {
      var data = $(this).data();
      $(VisualEdit.currentButtonSelector).data('key', data.key)
      VisualEdit.setSize(parseInt(data.width), parseInt(data.height));
      VisualEdit.setLabel(data.label);
      VisualEdit.persistCurrentPreset();
    });

    // Initialize the jQuery UI Resizable plugin
    VisualEdit.$resizableContainer.resizable({
      handles: 'w, sw, s, se, e'
    });

    VisualEdit.$resizableContainer.on('resizestart', function() {
      // Add iframe overlay to prevent losing the mouse focus to the iframe while resizing fast
      $(this).append('<div id="visualedit-iframe-cover" style="z-index:99;position:absolute;width:100%;top:0;left:0;height:100%;"></div>');
    });

    VisualEdit.$resizableContainer.on('resize', function(evt, ui) {
      ui.size.width = ui.originalSize.width + ((ui.size.width - ui.originalSize.width) * 2);
      if (ui.size.height < VisualEdit.minimalHeight) {
        ui.size.height = VisualEdit.minimalHeight;
      }
      if (ui.size.width < VisualEdit.minimalWidth) {
        ui.size.width = VisualEdit.minimalWidth;
      }
      $(VisualEdit.inputWidthSelector).val(ui.size.width);
      $(VisualEdit.inputHeightSelector).val(ui.size.height);
      VisualEdit.$resizableContainer.css({
        left: 0
      });
      VisualEdit.setLabel(VisualEdit.defaultLabel);
    });

    VisualEdit.$resizableContainer.on('resizestop', function() {
      $('#visualedit-iframe-cover').remove();
      VisualEdit.persistCurrentPreset();
      VisualEdit.persistCustomPreset();
    });
  };

  /**
   * @returns {Number}
   */
  VisualEdit.calculateContainerMaxHeight = function() {
    VisualEdit.$resizableContainer.hide();
    var $moduleBody = $(VisualEdit.moduleBodySelector);
    var padding = $moduleBody.outerHeight() - $moduleBody.height(),
      documentHeight = $(document).height();
    VisualEdit.$resizableContainer.show();
    return documentHeight - padding - 8;
  };

  /**
   * @returns {Number}
   */
  VisualEdit.calculateContainerMaxWidth = function() {
    VisualEdit.$resizableContainer.hide();
    var $moduleBody = $(VisualEdit.moduleBodySelector);
    var padding = $moduleBody.outerWidth() - $moduleBody.width(),
      documentWidth = $(document).width();
    VisualEdit.$resizableContainer.show();
    return parseInt(documentWidth - padding);
  };

  /**
   * @param {String} url
   * @returns {{}}
   */
  VisualEdit.getUrlVars = function(url) {
    var vars = {};
    var hash;
    var hashes = url.slice(url.indexOf('?') + 1).split('&');
    for (var i = 0; i < hashes.length; i++) {
      hash = hashes[i].split('=');
      vars[hash[0]] = hash[1];
    }
    return vars;
  };

  $(VisualEdit.initialize);

  return VisualEdit;
});
