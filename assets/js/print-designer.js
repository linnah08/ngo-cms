/**
 * PrintDesigner — canvas-based design placement tool.
 *
 * Usage:
 *   var designer = new PrintDesigner(canvasEl, hiddenInputEl, {x,y,w,h});
 *   designer.loadFile(fileObject);
 *
 * State is serialised as JSON into hiddenInputEl.value:
 *   {x, y, scale, rotation}  — all as fractions of canvas dimensions.
 *   x, y    = center of design
 *   scale   = rendered design width / canvas width
 *   rotation = radians
 */
(function (global) {
  'use strict';

  function PrintDesigner(canvas, posInput, printArea) {
    this.canvas   = canvas;
    this.ctx      = canvas.getContext('2d');
    this.posInput = posInput;
    this.pa       = printArea; // {x,y,w,h} fractions

    this.img     = null;
    this.pos     = { x: 0.5, y: 0.4, scale: 0.3, rotation: 0 };

    this.isDragging  = false;
    this.isRotating  = false;
    this.dragStart   = null;
    this.posStart    = null;
    this._pinchDist  = null;
    this._pinchScale = 0;

    var self = this;
    canvas.addEventListener('mousedown',  function (e) { self._onDown(e); });
    canvas.addEventListener('mousemove',  function (e) { self._onMove(e); });
    canvas.addEventListener('mouseup',    function ()  { self._onUp(); });
    canvas.addEventListener('mouseleave', function ()  { self._onUp(); });
    canvas.addEventListener('wheel', function (e) {
      self._onWheel(e);
      e.preventDefault();
    }, { passive: false });

    canvas.addEventListener('touchstart', function (e) { self._onTouchStart(e); }, { passive: false });
    canvas.addEventListener('touchmove',  function (e) { self._onTouchMove(e); },  { passive: false });
    canvas.addEventListener('touchend',   function ()  { self._onUp(); self._pinchDist = null; });

    this.render();
  }

  /** Load a File object as the design image. */
  PrintDesigner.prototype.loadFile = function (file) {
    var url  = URL.createObjectURL(file);
    var img  = new Image();
    var self = this;
    img.onload = function () {
      URL.revokeObjectURL(url);
      self.img = img;
      var pa = self.pa;
      self.pos = {
        x:        pa.x + pa.w / 2,
        y:        pa.y + pa.h / 2,
        scale:    pa.w * 0.5,
        rotation: 0,
      };
      self.render();
      self._serialize();
    };
    img.src = url;
  };

  /** Rendered pixel width/height of the design image on this canvas. */
  PrintDesigner.prototype._imgDim = function () {
    if (!this.img) return { w: 0, h: 0 };
    var pw = this.canvas.width * this.pos.scale;
    var ph = pw * (this.img.height / this.img.width);
    return { w: pw, h: ph };
  };

  /** Pixel coordinates of the rotation handle (top-right of design bounding box). */
  PrintDesigner.prototype._handlePos = function () {
    var c    = this.canvas;
    var d    = this._imgDim();
    var cx   = this.pos.x * c.width;
    var cy   = this.pos.y * c.height;
    var ang  = this.pos.rotation;
    var hx   =  d.w / 2;
    var hy   = -d.h / 2;
    return {
      x: cx + hx * Math.cos(ang) - hy * Math.sin(ang),
      y: cy + hx * Math.sin(ang) + hy * Math.cos(ang),
    };
  };

  PrintDesigner.prototype._nearHandle = function (mx, my) {
    var h = this._handlePos();
    return Math.hypot(mx - h.x, my - h.y) < 14;
  };

  /** Convert a mouse/touch event to canvas-local coordinates. */
  PrintDesigner.prototype._local = function (clientX, clientY) {
    var r    = this.canvas.getBoundingClientRect();
    var scaleX = this.canvas.width  / r.width;
    var scaleY = this.canvas.height / r.height;
    return {
      x: (clientX - r.left) * scaleX,
      y: (clientY - r.top)  * scaleY,
    };
  };

  PrintDesigner.prototype._onDown = function (e) {
    var p = this._local(e.clientX, e.clientY);
    if (this.img && this._nearHandle(p.x, p.y)) {
      this.isRotating = true;
    } else {
      this.isDragging = true;
      this.posStart   = { x: this.pos.x, y: this.pos.y };
    }
    this.dragStart = p;
  };

  PrintDesigner.prototype._onMove = function (e) {
    var c = this.canvas;
    var p = this._local(e.clientX, e.clientY);

    if (this.isRotating && this.img) {
      var cx = this.pos.x * c.width;
      var cy = this.pos.y * c.height;
      // Angle from design center to mouse, offset by 45° so handle sits at top-right
      this.pos.rotation = Math.atan2(p.y - cy, p.x - cx) - Math.PI / 4;
      this.render();
      this._serialize();
    } else if (this.isDragging && this.img) {
      this.pos.x = this.posStart.x + (p.x - this.dragStart.x) / c.width;
      this.pos.y = this.posStart.y + (p.y - this.dragStart.y) / c.height;
      this.render();
      this._serialize();
    }

    // Cursor feedback
    if (this.img && this._nearHandle(p.x, p.y)) {
      c.style.cursor = 'crosshair';
    } else {
      c.style.cursor = this.img ? 'move' : 'default';
    }
  };

  PrintDesigner.prototype._onUp = function () {
    this.isDragging = false;
    this.isRotating = false;
  };

  PrintDesigner.prototype._onWheel = function (e) {
    if (!this.img) return;
    var delta = e.deltaY > 0 ? -0.02 : 0.02;
    this.pos.scale = Math.max(0.05, Math.min(1.5, this.pos.scale + delta));
    this.render();
    this._serialize();
  };

  PrintDesigner.prototype._touchDist = function (e) {
    var dx = e.touches[0].clientX - e.touches[1].clientX;
    var dy = e.touches[0].clientY - e.touches[1].clientY;
    return Math.hypot(dx, dy);
  };

  PrintDesigner.prototype._onTouchStart = function (e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      this._pinchDist = null;
      this._onDown({ clientX: e.touches[0].clientX, clientY: e.touches[0].clientY });
    } else if (e.touches.length === 2) {
      this.isDragging  = false;
      this._pinchDist  = this._touchDist(e);
      this._pinchScale = this.pos.scale;
    }
  };

  PrintDesigner.prototype._onTouchMove = function (e) {
    e.preventDefault();
    if (e.touches.length === 1 && this._pinchDist === null) {
      this._onMove({ clientX: e.touches[0].clientX, clientY: e.touches[0].clientY });
    } else if (e.touches.length === 2) {
      var dist = this._touchDist(e);
      this.pos.scale = Math.max(0.05, Math.min(1.5, this._pinchScale * (dist / this._pinchDist)));
      this.render();
      this._serialize();
    }
  };

  PrintDesigner.prototype.render = function () {
    var c   = this.canvas;
    var ctx = this.ctx;
    ctx.clearRect(0, 0, c.width, c.height);

    // Draw design image
    if (this.img) {
      var d = this._imgDim();
      ctx.save();
      ctx.translate(this.pos.x * c.width, this.pos.y * c.height);
      ctx.rotate(this.pos.rotation);
      ctx.drawImage(this.img, -d.w / 2, -d.h / 2, d.w, d.h);
      ctx.restore();

      // Rotation handle — teal filled circle at top-right of design
      var h = this._handlePos();
      ctx.beginPath();
      ctx.arc(h.x, h.y, 8, 0, Math.PI * 2);
      ctx.fillStyle   = '#0387A5';
      ctx.fill();
      ctx.strokeStyle = '#ffffff';
      ctx.lineWidth   = 2;
      ctx.stroke();
    }

    // Print area dashed overlay — always on top
    var pa = this.pa;
    ctx.strokeStyle = '#0387A5';
    ctx.lineWidth   = 2;
    ctx.setLineDash([6, 4]);
    ctx.strokeRect(
      pa.x * c.width,
      pa.y * c.height,
      pa.w * c.width,
      pa.h * c.height
    );
    ctx.setLineDash([]);
  };

  PrintDesigner.prototype._serialize = function () {
    this.posInput.value = JSON.stringify({
      x:        Math.round(this.pos.x        * 1000) / 1000,
      y:        Math.round(this.pos.y        * 1000) / 1000,
      scale:    Math.round(this.pos.scale    * 1000) / 1000,
      rotation: Math.round(this.pos.rotation * 1000) / 1000,
    });
  };

  global.PrintDesigner = PrintDesigner;
}(window));
