// voice-order.js — สั่งอาหารด้วยเสียงพูด
(function (global) {
    'use strict';

    var SR = global.SpeechRecognition || global.webkitSpeechRecognition;

    var VoiceOrder = {
        cfg: null,
        recognition: null,
        listening: false,
        lastItems: [],

        supported: function () {
            return !!SR;
        },

        init: function (cfg) {
            this.cfg = Object.assign({
                apiUrl: '/restaurant-backoffice/api/parse_voice_order.php',
                lang: 'th-TH',
                button: null,
                panel: null,
                onConfirm: function () {}
            }, cfg || {});

            if (!this.cfg.button) return;

            if (!this.supported()) {
                this.cfg.button.disabled = true;
                this.cfg.button.title = 'เบราว์เซอร์นี้ไม่รองรับการสั่งด้วยเสียง กรุณาใช้ Google Chrome';
                this.render('<div class="alert alert-warning mb-0">' +
                    '<i class="ti ti-microphone-off"></i> เบราว์เซอร์นี้ไม่รองรับการรับเสียง ' +
                    'กรุณาเปิดด้วย Google Chrome หรือ Microsoft Edge</div>');
                return;
            }

            var self = this;
            this.cfg.button.addEventListener('click', function () {
                self.listening ? self.stop() : self.start();
            });
        },

        // ---------------- การรับเสียง ----------------

        start: function () {
            var self = this;
            var r = new SR();
            r.lang = this.cfg.lang;
            r.interimResults = true;   // แสดงข้อความระหว่างพูด ให้ผู้ใช้เห็นว่าระบบได้ยิน
            r.continuous = false;
            r.maxAlternatives = 1;

            r.onstart = function () {
                self.listening = true;
                self.setButton(true);
                self.render('<div class="text-center py-3">' +
                    '<div class="voice-pulse mb-2"><i class="ti ti-microphone fs-1"></i></div>' +
                    '<div class="fw-bold">กำลังฟัง… พูดได้เลย</div>' +
                    '<div class="text-muted small">เช่น "กะเพราหมูสองจาน ไข่ดาวด้วย แล้วก็ข้าวผัดกุ้งหนึ่งที่"</div>' +
                    '</div>');
            };

            r.onresult = function (e) {
                var finalText = '', interim = '';
                for (var i = e.resultIndex; i < e.results.length; i++) {
                    if (e.results[i].isFinal) finalText += e.results[i][0].transcript;
                    else interim += e.results[i][0].transcript;
                }
                if (interim) {
                    self.render('<div class="text-center py-3">' +
                        '<div class="voice-pulse mb-2"><i class="ti ti-microphone fs-1"></i></div>' +
                        '<div class="text-muted">' + self.esc(interim) + '</div></div>');
                }
                if (finalText) self.send(finalText.trim());
            };

            r.onerror = function (e) {
                self.listening = false;
                self.setButton(false);
                var msg = {
                    'no-speech': 'ไม่ได้ยินเสียง กรุณาลองพูดอีกครั้ง',
                    'audio-capture': 'ไม่พบไมโครโฟน กรุณาตรวจสอบอุปกรณ์',
                    'not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้ไมโครโฟน กรุณากดอนุญาตแล้วลองใหม่',
                    'network': 'การรับเสียงต้องใช้อินเทอร์เน็ต กรุณาตรวจสอบการเชื่อมต่อ'
                }[e.error] || ('เกิดข้อผิดพลาด: ' + e.error);
                self.render('<div class="alert alert-warning mb-0"><i class="ti ti-alert-triangle"></i> ' +
                            self.esc(msg) + '</div>');
            };

            r.onend = function () {
                self.listening = false;
                self.setButton(false);
            };

            this.recognition = r;
            try { r.start(); } catch (err) { /* กดซ้ำเร็วเกินไป */ }
        },

        stop: function () {
            if (this.recognition) this.recognition.stop();
            this.listening = false;
            this.setButton(false);
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
                self.renderResult(transcript, data);
            })
            .catch(function () {
                self.render('<div class="alert alert-danger mb-0">เชื่อมต่อเซิร์ฟเวอร์ไม่ได้</div>');
            });
        },

        // ---------------- แสดงผลให้ยืนยัน ----------------

        renderResult: function (transcript, data) {
            var items = data.items || [];
            var unmatched = data.unmatched || [];
            var html = '';

            html += '<div class="small text-muted mb-2"><i class="ti ti-quote"></i> ได้ยินว่า: "' +
                    this.esc(transcript) + '"</div>';

            if (!items.length && !(data.unavailable || []).length) {
                html += '<div class="alert alert-warning mb-2">' +
                        '<i class="ti ti-alert-triangle"></i> ไม่พบเมนูที่ตรงกับที่พูด กรุณาลองใหม่ หรือเลือกจากเมนูด้านล่าง</div>';
            } else {
                html += '<ul class="list-group mb-2">';
                items.forEach(function (it, idx) {
                    var addonText = (it.addons || []).map(function (a) { return a.name; }).join(', ');
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
                                '</div>' +
                                (addonText ? '<div class="small text-muted">+ ' + this.esc(addonText) + '</div>' : '') +
                            '</div>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger" data-vo-del="' + idx + '">' +
                                '<i class="ti ti-trash"></i></button>' +
                        '</div></li>';
                }, this);
                html += '</ul>';
            }

            if ((data.unavailable || []).length) {
                html += '<div class="alert alert-danger small mb-2">' +
                        '<i class="ti ti-ban"></i> สั่งไม่ได้ตอนนี้: ' +
                        this.esc(data.unavailable.join(' / ')) + '</div>';
            }

            if (unmatched.length) {
                html += '<div class="alert alert-light border small mb-2">' +
                        '<i class="ti ti-help-circle"></i> ส่วนที่ระบบไม่เข้าใจ: ' +
                        this.esc(unmatched.join(' / ')) + '</div>';
            }

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
                    self.renderResult('(แก้ไขแล้ว)', { items: self.lastItems, unmatched: [] });
                });
            });
            var confirmBtn = panel.querySelector('[data-vo-confirm]');
            if (confirmBtn) confirmBtn.addEventListener('click', function () {
                self.cfg.onConfirm(self.lastItems.slice());
                self.render('<div class="alert alert-success mb-0">' +
                    '<i class="ti ti-circle-check"></i> เพิ่มลงตะกร้าเรียบร้อย</div>');
            });
            var retryBtn = panel.querySelector('[data-vo-retry]');
            if (retryBtn) retryBtn.addEventListener('click', function () { self.start(); });
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
            var b = this.cfg.button;
            if (!b) return;
            b.classList.toggle('listening', active);
            if (b.classList.contains('btn')) {
                b.classList.toggle('btn-danger', active);
                b.classList.toggle('btn-dark', !active);
            }
            b.innerHTML = active
                ? '<i class="ti ti-player-stop"></i> หยุดฟัง'
                : '<i class="ti ti-microphone"></i> สั่งด้วยเสียง';
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
