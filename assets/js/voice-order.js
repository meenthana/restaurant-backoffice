// voice-order.js — สั่งอาหารด้วยเสียงพูด
// mode 'confirm' (หน้าพนักงาน/ออนไลน์): แสดงผลให้กดยืนยันก่อน
// mode 'auto' (ตู้): ใส่ตะกร้าทันที แสดงผลพร้อมปุ่มเอาออก · กดค้างแล้วพูด ปล่อยเมื่อพูดจบ ตัดเสียงช่วงที่ไม่ได้กด
(function (global) {
    'use strict';

    var SR = global.SpeechRecognition || global.webkitSpeechRecognition;
    var TAP_MS = 350;        // กดสั้นกว่านี้ = แตะเพื่อเริ่มฟัง (หยุดเองเมื่อเงียบ)
    var MAX_LISTEN_MS = 15000;

    var VoiceOrder = {
        cfg: null,
        recognition: null,
        listening: false,
        lastItems: [],
        heard: '',
        interim: '',
        holdTimer: null,
        maxTimer: null,

        supported: function () {
            return !!SR;
        },

        init: function (cfg) {
            this.cfg = Object.assign({
                apiUrl: '/restaurant-backoffice/api/parse_voice_order.php',
                lang: 'th-TH',
                mode: 'confirm',
                button: null,
                holdButtons: [],
                panel: null,
                onConfirm: function () {},
                onAdd: function () { return []; },
                onRemove: function () {},
                onStart: function () {},
                onDone: function () {}
            }, cfg || {});

            var buttons = [this.cfg.button].concat(this.cfg.holdButtons || []).filter(Boolean);
            if (!buttons.length) return;

            if (!this.supported()) {
                buttons.forEach(function (b) {
                    b.disabled = true;
                    b.title = 'เบราว์เซอร์นี้ไม่รองรับการสั่งด้วยเสียง กรุณาใช้ Google Chrome';
                });
                this.render('<div class="alert alert-warning mb-0">' +
                    '<i class="ti ti-microphone-off"></i> เบราว์เซอร์นี้ไม่รองรับการรับเสียง ' +
                    'กรุณาเปิดด้วย Google Chrome หรือ Microsoft Edge</div>');
                return;
            }

            var self = this;
            if (this.cfg.mode === 'auto') {
                buttons.forEach(function (b) { self.bindHold(b); });
            } else {
                this.cfg.button.addEventListener('click', function () {
                    self.listening ? self.stop() : self.start(false);
                });
            }
        },

        // กดค้าง = ฟังเฉพาะช่วงที่กด · แตะสั้น ๆ = ฟังจนกว่าจะเงียบ · แตะซ้ำระหว่างฟัง = หยุด
        bindHold: function (btn) {
            var self = this, downAt = 0, wasListening = false;
            btn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
            btn.addEventListener('pointerdown', function (e) {
                if (e.button !== undefined && e.button !== 0) return;
                e.preventDefault();
                wasListening = self.listening;
                if (wasListening) { self.stop(); return; }
                downAt = Date.now();
                self.start(true);
                var up = function () {
                    global.removeEventListener('pointerup', up);
                    global.removeEventListener('pointercancel', up);
                    if (Date.now() - downAt >= TAP_MS) self.stop();
                    else self.switchToTap();
                };
                global.addEventListener('pointerup', up);
                global.addEventListener('pointercancel', up);
            });
            btn.addEventListener('keydown', function (e) {
                if ((e.key === 'Enter' || e.key === ' ') && !e.repeat) {
                    e.preventDefault();
                    self.listening ? self.stop() : self.start(false);
                }
            });
        },

        // ---------------- การรับเสียง ----------------

        start: function (hold) {
            if (this.listening) return;
            var self = this;
            var r = new SR();
            r.lang = this.cfg.lang;
            r.interimResults = true;     // แสดงข้อความระหว่างพูด ให้ผู้ใช้เห็นว่าระบบได้ยิน
            r.continuous = !!hold;       // กดค้าง: ฟังต่อแม้หยุดหายใจ จนกว่าจะปล่อยปุ่ม
            r.maxAlternatives = 1;
            this.heard = '';
            this.interim = '';
            this.hold = !!hold;

            r.onstart = function () {
                self.listening = true;
                self.setButton(true);
                self.cfg.onStart();
                self.render('<div class="text-center py-3">' +
                    '<div class="voice-pulse mb-2"><i class="ti ti-microphone fs-1"></i></div>' +
                    '<div class="fw-bold">' + (self.hold ? 'กำลังฟัง… พูดแล้วปล่อยปุ่ม' : 'กำลังฟัง… พูดได้เลย') + '</div>' +
                    '<div class="text-muted small">เช่น "กะเพราหมูสับไข่ดาวไม่เผ็ด แล้วก็ข้าวผัดกุ้งสองจาน"</div>' +
                    '</div>');
            };

            r.onresult = function (e) {
                var interim = '';
                for (var i = e.resultIndex; i < e.results.length; i++) {
                    if (e.results[i].isFinal) self.heard += ' ' + e.results[i][0].transcript;
                    else interim += e.results[i][0].transcript;
                }
                self.interim = interim;
                var shown = (self.heard + ' ' + interim).trim();
                if (shown) {
                    self.render('<div class="text-center py-3">' +
                        '<div class="voice-pulse mb-2"><i class="ti ti-microphone fs-1"></i></div>' +
                        '<div class="text-muted">' + self.esc(shown) + '</div></div>');
                }
            };

            r.onerror = function (e) {
                self.failed = true;
                var msg = {
                    'no-speech': 'ไม่ได้ยินเสียง กรุณาลองพูดอีกครั้ง',
                    'audio-capture': 'ไม่พบไมโครโฟน กรุณาตรวจสอบอุปกรณ์',
                    'not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้ไมโครโฟน กรุณากดอนุญาตแล้วลองใหม่',
                    'network': 'การรับเสียงต้องใช้อินเทอร์เน็ต กรุณาตรวจสอบการเชื่อมต่อ',
                    'aborted': ''
                }[e.error];
                if (msg === undefined) msg = 'เกิดข้อผิดพลาด: ' + e.error;
                if (msg) self.render('<div class="alert alert-warning mb-0"><i class="ti ti-alert-triangle"></i> ' + self.esc(msg) + '</div>');
            };

            r.onend = function () {
                clearTimeout(self.maxTimer);
                self.listening = false;
                self.setButton(false);
                var text = (self.heard + ' ' + self.interim).trim();
                if (text) self.send(text);
                else if (!self.failed) self.render('<div class="alert alert-warning mb-0"><i class="ti ti-alert-triangle"></i> ไม่ได้ยินเสียง กรุณากดค้างแล้วพูดอีกครั้ง</div>');
                self.failed = false;
            };

            this.recognition = r;
            this.failed = false;
            try { r.start(); } catch (err) { return; }
            clearTimeout(this.maxTimer);
            this.maxTimer = setTimeout(function () { self.stop(); }, MAX_LISTEN_MS);
        },

        // แตะสั้น: เปลี่ยนเป็นฟังจนกว่าจะเงียบ (ระบบหยุดเอง)
        switchToTap: function () {
            this.hold = false;
            if (this.recognition) this.recognition.continuous = false;
        },

        stop: function () {
            if (this.recognition && this.listening) this.recognition.stop();
        },

        // ---------------- ส่งไปตีความ ----------------

        send: function (transcript) {
            var self = this;
            this.render('<div class="text-center py-3 text-muted">' +
                '<div class="spinner-border spinner-border-sm me-2"></div>กำลังตีความ "' +
                this.esc(transcript) + '"</div>');

            fetch(this.cfg.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ transcript: transcript })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    self.render('<div class="alert alert-danger mb-0">' + self.esc(data.error || 'ตีความไม่สำเร็จ') + '</div>');
                    return;
                }
                self.lastItems = data.items || [];
                if (self.cfg.mode === 'auto') self.renderAdded(transcript, data);
                else self.renderResult(transcript, data);
            })
            .catch(function () {
                self.render('<div class="alert alert-danger mb-0">เชื่อมต่อเซิร์ฟเวอร์ไม่ได้</div>');
            });
        },

        detail: function (it) {
            var addonText = (it.addons || []).map(function (a) { return a.name; }).join(', ');
            return (addonText ? '<div class="small text-muted">+ ' + this.esc(addonText) + '</div>' : '') +
                (it.note ? '<div class="small text-danger fw-bold"><i class="ti ti-message-2"></i> ' + this.esc(it.note) + '</div>' : '');
        },

        extras: function (data) {
            var html = '';
            if ((data.unavailable || []).length) {
                html += '<div class="alert alert-danger small mb-2"><i class="ti ti-ban"></i> สั่งไม่ได้ตอนนี้: ' +
                        this.esc(data.unavailable.join(' / ')) + '</div>';
            }
            var ignored = data.ignored || data.unmatched || [];
            if (ignored.length) {
                html += '<div class="small text-muted mb-2"><i class="ti ti-volume-off"></i> ไม่ได้ใช้ (ไม่ใช่รายการอาหาร): ' +
                        this.esc(ignored.join(' / ')) + '</div>';
            }
            return html;
        },

        // ---------------- โหมดตู้: ใส่ตะกร้าทันที ----------------

        renderAdded: function (transcript, data) {
            var self = this;
            var items = data.items || [];
            var handles = items.length ? (this.cfg.onAdd(items) || []) : [];
            var html = '<div class="small text-muted mb-2"><i class="ti ti-quote"></i> ได้ยินว่า: "' + this.esc(transcript) + '"</div>';

            if (!items.length) {
                html += '<div class="alert alert-warning mb-2"><i class="ti ti-alert-triangle"></i> ' +
                        'ไม่พบเมนูที่ตรงกับที่พูด กรุณาลองใหม่ หรือแตะเลือกจากเมนู</div>';
            } else {
                html += '<div class="fw-bold text-success mb-2"><i class="ti ti-circle-check"></i> ใส่ตะกร้าแล้ว</div>';
                html += '<ul class="list-group mb-2">';
                items.forEach(function (it, idx) {
                    html += '<li class="list-group-item' + (it.needs_confirm ? ' list-group-item-warning' : '') + '" data-vo-row="' + idx + '">' +
                        '<div class="d-flex align-items-center gap-2"><div class="flex-grow-1">' +
                        '<div class="fw-bold">' + self.esc(it.menu_name) + ' × ' + it.quantity +
                        (it.needs_confirm ? ' <span class="badge text-bg-warning">ไม่แน่ใจ ตรวจอีกครั้ง</span>' : '') + '</div>' +
                        self.detail(it) + '</div>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger" data-vo-undo="' + idx + '">' +
                        '<i class="ti ti-trash"></i> เอาออก</button></div></li>';
                });
                html += '</ul>';
            }
            html += this.extras(data);
            html += '<div class="d-flex gap-2"><button type="button" class="btn btn-dark flex-grow-1" data-vo-done>' +
                    '<i class="ti ti-check"></i> เสร็จ</button></div>';
            this.render(html);

            var panel = this.cfg.panel;
            panel.querySelectorAll('[data-vo-undo]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var i = +b.dataset.voUndo;
                    if (handles[i]) self.cfg.onRemove(handles[i]);
                    handles[i] = null;
                    var row = panel.querySelector('[data-vo-row="' + i + '"]');
                    if (row) row.remove();
                });
            });
            var done = panel.querySelector('[data-vo-done]');
            if (done) done.addEventListener('click', function () { self.cfg.onDone(); });
            if (items.length && !items.some(function (it) { return it.needs_confirm; })) {
                this.cfg.onDone(true);
            }
        },

        // ---------------- โหมดยืนยัน (หน้าพนักงาน/ออนไลน์) ----------------

        renderResult: function (transcript, data) {
            var items = data.items || [];
            var html = '';

            html += '<div class="small text-muted mb-2"><i class="ti ti-quote"></i> ได้ยินว่า: "' +
                    this.esc(transcript) + '"</div>';

            if (!items.length && !(data.unavailable || []).length) {
                html += '<div class="alert alert-warning mb-2">' +
                        '<i class="ti ti-alert-triangle"></i> ไม่พบเมนูที่ตรงกับที่พูด กรุณาลองใหม่ หรือเลือกจากเมนูด้านล่าง</div>';
            } else {
                html += '<ul class="list-group mb-2">';
                items.forEach(function (it, idx) {
                    html += '<li class="list-group-item">' +
                        '<div class="d-flex align-items-center gap-2">' +
                            '<div class="input-group input-group-sm" style="width:110px;">' +
                                '<button type="button" class="btn btn-outline-secondary" data-vo-dec="' + idx + '">-</button>' +
                                '<input type="number" min="1" class="form-control text-center" ' +
                                       'value="' + it.quantity + '" data-vo-qty="' + idx + '">' +
                                '<button type="button" class="btn btn-outline-secondary" data-vo-inc="' + idx + '">+</button>' +
                            '</div>' +
                            '<div class="flex-grow-1">' +
                                '<div class="fw-bold">' + this.esc(it.menu_name) +
                                    (it.needs_confirm ? ' <span class="badge text-bg-warning">ไม่แน่ใจ</span>' : '') +
                                '</div>' + this.detail(it) +
                            '</div>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger" data-vo-del="' + idx + '">' +
                                '<i class="ti ti-trash"></i></button>' +
                        '</div></li>';
                }, this);
                html += '</ul>';
            }
            html += this.extras(data);

            html += '<div class="d-flex gap-2">' +
                    (items.length ? '<button type="button" class="btn btn-dark flex-grow-1" data-vo-confirm>' +
                        '<i class="ti ti-check"></i> ยืนยันใส่ตะกร้า</button>' : '') +
                    '<button type="button" class="btn btn-outline-secondary" data-vo-retry>' +
                        '<i class="ti ti-microphone"></i> พูดใหม่</button></div>';

            this.render(html);
            this.bindResult();
        },

        bindResult: function () {
            var self = this;
            var panel = this.cfg.panel;
            if (!panel) return;

            panel.querySelectorAll('[data-vo-qty]').forEach(function (el) {
                el.addEventListener('change', function () {
                    var i = +el.dataset.voQty;
                    self.lastItems[i].quantity = Math.max(1, parseInt(el.value, 10) || 1);
                    el.value = self.lastItems[i].quantity;
                });
            });
            panel.querySelectorAll('[data-vo-inc]').forEach(function (el) {
                el.addEventListener('click', function () { self.bump(+el.dataset.voInc, 1); });
            });
            panel.querySelectorAll('[data-vo-dec]').forEach(function (el) {
                el.addEventListener('click', function () { self.bump(+el.dataset.voDec, -1); });
            });
            panel.querySelectorAll('[data-vo-del]').forEach(function (el) {
                el.addEventListener('click', function () {
                    self.lastItems.splice(+el.dataset.voDel, 1);
                    self.renderResult('(แก้ไขแล้ว)', { items: self.lastItems });
                });
            });
            var confirmBtn = panel.querySelector('[data-vo-confirm]');
            if (confirmBtn) confirmBtn.addEventListener('click', function () {
                self.cfg.onConfirm(self.lastItems.slice());
                self.render('<div class="alert alert-success mb-0">' +
                    '<i class="ti ti-circle-check"></i> เพิ่มลงตะกร้าเรียบร้อย</div>');
            });
            var retryBtn = panel.querySelector('[data-vo-retry]');
            if (retryBtn) retryBtn.addEventListener('click', function () { self.start(false); });
        },

        bump: function (idx, delta) {
            var it = this.lastItems[idx];
            if (!it) return;
            it.quantity = Math.max(1, it.quantity + delta);
            var input = this.cfg.panel.querySelector('[data-vo-qty="' + idx + '"]');
            if (input) input.value = it.quantity;
        },

        // ---------------- เครื่องมือย่อย ----------------

        setButton: function (active) {
            var self = this;
            [this.cfg.button].concat(this.cfg.holdButtons || []).filter(Boolean).forEach(function (b) {
                b.classList.toggle('listening', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
                if (b.classList.contains('btn')) {
                    b.classList.toggle('btn-danger', active);
                    b.classList.toggle('btn-dark', !active);
                }
                if (b.dataset.voLabel === 'none') return;
                b.innerHTML = active
                    ? '<i class="ti ti-player-stop"></i> ' + (self.cfg.mode === 'auto' ? 'กำลังฟัง… ปล่อยเมื่อพูดจบ' : 'หยุดฟัง')
                    : '<i class="ti ti-microphone"></i> ' + (self.cfg.mode === 'auto' ? 'กดค้างแล้วพูด' : 'สั่งด้วยเสียง');
            });
        },

        render: function (html) {
            if (this.cfg.panel) this.cfg.panel.innerHTML = html;
        },

        esc: function (s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    };

    global.VoiceOrder = VoiceOrder;
})(window);
