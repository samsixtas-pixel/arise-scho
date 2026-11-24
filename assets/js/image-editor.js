/* ============================================================
   image-editor.js — reusable crop / rotate / zoom editor
   ------------------------------------------------------------
   Exposes window.LmsImageEditor. Used by the customer form and
   by the phone capture page. Draws a fixed aspect-ratio canvas
   (the crop frame) with cover-fit, zoom and pan.
   ============================================================ */
(function () {
  'use strict';

  function LmsImageEditor(options) {
    this.canvas = options.canvas;
    this.stage = options.stage || null;
    this.aspect = options.aspect || 3 / 4;
    this.maxEdge = options.maxEdge || 1200;
    this.quality = options.quality || 0.9;
    this.ctx = this.canvas.getContext('2d');
    this.img = null;
    this.rotation = 0;
    this.zoom = 1;
    this.ox = 0;
    this.oy = 0;
    this._drag = null;
    this._bound = false;

    this.resizeCanvas();
    this.bindPointer();
  }

  LmsImageEditor.prototype.resizeCanvas = function () {
    const longEdge = this.maxEdge;
    let w;
    let h;

    if (this.aspect >= 1) {
      w = longEdge;
      h = Math.round(longEdge / this.aspect);
    } else {
      h = longEdge;
      w = Math.round(longEdge * this.aspect);
    }

    this.canvas.width = w;
    this.canvas.height = h;

    if (this.stage) {
      this.stage.style.aspectRatio = String(this.aspect);
    }
  };

  LmsImageEditor.prototype.load = function (src) {
    const self = this;

    return new Promise(function (resolve, reject) {
      const img = new Image();

      img.onload = function () {
        self.img = img;
        self.rotation = 0;
        self.zoom = 1;
        self.ox = 0;
        self.oy = 0;
        self.render();
        resolve(self);
      };

      img.onerror = function () {
        reject(new Error('The image could not be loaded.'));
      };

      img.src = src;
    });
  };

  LmsImageEditor.prototype.rotatedSize = function () {
    const normal = Math.abs(this.rotation % 180) === 0;

    return {
      width: normal ? this.img.width : this.img.height,
      height: normal ? this.img.height : this.img.width
    };
  };

  LmsImageEditor.prototype.baseScale = function () {
    if (!this.img) return 1;

    const size = this.rotatedSize();

    return Math.max(this.canvas.width / size.width, this.canvas.height / size.height);
  };

  LmsImageEditor.prototype.clampOffsets = function () {
    if (!this.img) return;

    const size = this.rotatedSize();
    const scale = this.baseScale() * this.zoom;
    const maxX = Math.max(0, (size.width * scale - this.canvas.width) / 2);
    const maxY = Math.max(0, (size.height * scale - this.canvas.height) / 2);

    this.ox = Math.max(-maxX, Math.min(maxX, this.ox));
    this.oy = Math.max(-maxY, Math.min(maxY, this.oy));
  };

  LmsImageEditor.prototype.render = function () {
    if (!this.img) return;

    const ctx = this.ctx;
    const cw = this.canvas.width;
    const ch = this.canvas.height;

    ctx.save();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, cw, ch);
    ctx.translate(cw / 2, ch / 2);
    ctx.rotate(this.rotation * Math.PI / 180);

    const scale = this.baseScale() * this.zoom;

    ctx.scale(scale, scale);
    ctx.drawImage(
      this.img,
      -this.img.width / 2 + this.ox / scale,
      -this.img.height / 2 + this.oy / scale
    );

    ctx.restore();
  };

  LmsImageEditor.prototype.setZoom = function (value) {
    this.zoom = Math.max(1, Math.min(4, parseFloat(value) || 1));
    this.clampOffsets();
    this.render();
  };

  LmsImageEditor.prototype.rotate = function (delta) {
    this.rotation = (this.rotation + (delta || 90) + 360) % 360;
    this.ox = 0;
    this.oy = 0;
    this.clampOffsets();
    this.render();
  };

  LmsImageEditor.prototype.reset = function () {
    this.rotation = 0;
    this.zoom = 1;
    this.ox = 0;
    this.oy = 0;
    this.render();
  };

  LmsImageEditor.prototype.bindPointer = function () {
    if (this._bound || !this.stage) return;

    const self = this;

    this.stage.addEventListener('pointerdown', function (event) {
      if (!self.img) return;
      self.stage.setPointerCapture(event.pointerId);
      self._drag = {
        x: event.clientX,
        y: event.clientY,
        ox: self.ox,
        oy: self.oy
      };
      self.stage.classList.add('dragging');
    });

    this.stage.addEventListener('pointermove', function (event) {
      if (!self._drag || !self.img) return;

      const rect = self.canvas.getBoundingClientRect();
      const ratioX = self.canvas.width / Math.max(1, rect.width);
      const ratioY = self.canvas.height / Math.max(1, rect.height);

      self.ox = self._drag.ox + (event.clientX - self._drag.x) * ratioX;
      self.oy = self._drag.oy + (event.clientY - self._drag.y) * ratioY;
      self.clampOffsets();
      self.render();
    });

    const end = function () {
      self._drag = null;
      self.stage.classList.remove('dragging');
    };

    this.stage.addEventListener('pointerup', end);
    this.stage.addEventListener('pointercancel', end);

    this.stage.addEventListener('wheel', function (event) {
      if (!self.img) return;
      event.preventDefault();
      self.setZoom(self.zoom + (event.deltaY < 0 ? 0.08 : -0.08));
    }, { passive: false });

    this._bound = true;
  };

  LmsImageEditor.prototype.toDataURL = function () {
    return this.canvas.toDataURL('image/jpeg', this.quality);
  };

  window.LmsImageEditor = LmsImageEditor;
})();
