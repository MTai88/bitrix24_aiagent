(function () {
	if (window.BX && BX.Mtai && BX.Mtai.Aiagent && BX.Mtai.Aiagent.Voice) {
		return;
	}

	BX.namespace('BX.Mtai.Aiagent');

	var state = {
		botId: '',
		maxSeconds: 60,
		recording: false,
		starting: false,
		busy: false,
		timer: null,
		startedAt: 0,
		stream: null,
		context: null,
		chunks: [],
		node: null,
		observer: null,
	};

	function notify(text) {
		if (BX.UI && BX.UI.Notification && BX.UI.Notification.Center) {
			BX.UI.Notification.Center.notify({content: text, autoHideDelay: 4000});
			return;
		}
		window.alert(text);
	}

	function currentDialogId() {
		try {
			var core = BX.Messenger && BX.Messenger.v2 && BX.Messenger.v2.Application && BX.Messenger.v2.Application.Core;
			if (!core || !core.getStore) {
				return '';
			}
			var store = core.getStore();
			if (!store) {
				return '';
			}
			var layout = store.getters['application/getLayout'];
			return layout && layout.entityId ? String(layout.entityId) : '';
		} catch (e) {
			return '';
		}
	}

	function inBotChat() {
		return state.botId !== '' && currentDialogId() === String(state.botId);
	}

	function ensureButton(bottom) {
		var existing = bottom.querySelector('[data-mtai-voice]');
		if (existing) {
			return existing;
		}

		var wrap = document.createElement('span');
		wrap.className = 'mtai-voice';
		wrap.setAttribute('data-mtai-voice', '1');
		wrap.innerHTML = ''
			+ '<button type="button" class="mtai-voice__btn" title="Голосовое сообщение">'
			+ '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V20H9v2h6v-2h-2v-2.08A7 7 0 0 0 19 11h-2z"/></svg>'
			+ '</button>'
			+ '<span class="mtai-voice__timer" hidden>0:00</span>'
			+ '<button type="button" class="mtai-voice__cancel" hidden title="Отменить">×</button>';

		wrap.querySelector('.mtai-voice__btn').addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			if (state.busy || state.starting) {
				return;
			}
			if (state.recording) {
				stop(false);
			} else {
				start();
			}
		});
		wrap.querySelector('.mtai-voice__cancel').addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			stop(true);
		});

		if (bottom.lastElementChild) {
			bottom.insertBefore(wrap, bottom.lastElementChild);
		} else {
			bottom.appendChild(wrap);
		}

		return wrap;
	}

	function sync() {
		var bottoms = document.querySelectorAll('.bx-im-textarea__bottom');
		var show = inBotChat();
		for (var i = 0; i < bottoms.length; i++) {
			var node = bottoms[i].querySelector('[data-mtai-voice]');
			if (!show) {
				if (node) {
					node.hidden = true;
				}
				continue;
			}
			ensureButton(bottoms[i]).hidden = false;
		}
		if (!show && state.recording) {
			stop(true);
		}
	}

	function formatTime(seconds) {
		var whole = Math.max(0, Math.floor(seconds));
		var minutes = Math.floor(whole / 60);
		var rest = whole % 60;
		return minutes + ':' + (rest < 10 ? '0' : '') + rest;
	}

	function eachButton(callback) {
		var nodes = document.querySelectorAll('[data-mtai-voice]');
		for (var i = 0; i < nodes.length; i++) {
			callback(nodes[i]);
		}
	}

	function setRecordingUi(on) {
		eachButton(function (node) {
			node.classList.toggle('mtai-voice--recording', on);
			var timer = node.querySelector('.mtai-voice__timer');
			var cancel = node.querySelector('.mtai-voice__cancel');
			if (timer) {
				timer.hidden = !on;
			}
			if (cancel) {
				cancel.hidden = !on;
			}
		});
	}

	function setBusy(on) {
		state.busy = on;
		eachButton(function (node) {
			node.classList.toggle('mtai-voice--busy', on);
		});
	}

	function workletSource() {
		return [
			'class MtaiPcm extends AudioWorkletProcessor {',
			'  constructor() { super(); this.ratio = sampleRate / 16000; this.acc = 0; this.sum = 0; this.count = 0; this.buf = []; }',
			'  process(inputs) {',
			'    var input = inputs[0] && inputs[0][0];',
			'    if (!input) return true;',
			'    for (var i = 0; i < input.length; i++) {',
			'      this.sum += input[i]; this.count++; this.acc++;',
			'      if (this.acc >= this.ratio) {',
			'        var sample = this.count ? this.sum / this.count : 0;',
			'        sample = Math.max(-1, Math.min(1, sample));',
			'        this.buf.push(sample < 0 ? sample * 0x8000 : sample * 0x7FFF);',
			'        this.acc -= this.ratio; this.sum = 0; this.count = 0;',
			'        if (this.buf.length >= 1600) { this.port.postMessage(this.buf.slice()); this.buf = []; }',
			'      }',
			'    }',
			'    return true;',
			'  }',
			'}',
			"registerProcessor('mtai-pcm', MtaiPcm);",
		].join('\n');
	}

	function pushSamples(samples) {
		var pcm = new Int16Array(samples.length);
		for (var i = 0; i < samples.length; i++) {
			pcm[i] = samples[i];
		}
		state.chunks.push(pcm);
	}

	function encodeWav(chunks) {
		var length = 0;
		chunks.forEach(function (chunk) {
			length += chunk.length;
		});
		var buffer = new ArrayBuffer(44 + length * 2);
		var view = new DataView(buffer);
		function write(offset, text) {
			for (var i = 0; i < text.length; i++) {
				view.setUint8(offset + i, text.charCodeAt(i));
			}
		}
		write(0, 'RIFF');
		view.setUint32(4, 36 + length * 2, true);
		write(8, 'WAVE');
		write(12, 'fmt ');
		view.setUint32(16, 16, true);
		view.setUint16(20, 1, true);
		view.setUint16(22, 1, true);
		view.setUint32(24, 16000, true);
		view.setUint32(28, 32000, true);
		view.setUint16(32, 2, true);
		view.setUint16(34, 16, true);
		write(36, 'data');
		view.setUint32(40, length * 2, true);
		var offset = 44;
		chunks.forEach(function (chunk) {
			for (var i = 0; i < chunk.length; i++) {
				view.setInt16(offset, chunk[i], true);
				offset += 2;
			}
		});
		return new Blob([buffer], {type: 'audio/wav'});
	}

	function cleanupAudio() {
		if (state.timer) {
			clearInterval(state.timer);
			state.timer = null;
		}
		if (state.node) {
			try {
				state.node.disconnect();
			} catch (e) {}
			state.node = null;
		}
		if (state.stream) {
			state.stream.getTracks().forEach(function (track) {
				track.stop();
			});
			state.stream = null;
		}
		if (state.context) {
			var context = state.context;
			state.context = null;
			context.close().catch(function () {});
		}
	}

	function downsampleFloat(input, fromRate) {
		var ratio = fromRate / 16000;
		var out = [];
		var acc = 0;
		var sum = 0;
		var count = 0;
		for (var i = 0; i < input.length; i++) {
			sum += input[i];
			count++;
			acc++;
			if (acc >= ratio) {
				var sample = count ? sum / count : 0;
				sample = Math.max(-1, Math.min(1, sample));
				out.push(sample < 0 ? sample * 0x8000 : sample * 0x7FFF);
				acc -= ratio;
				sum = 0;
				count = 0;
			}
		}
		return out;
	}

	function attachProcessor(context, source) {
		var gain = context.createGain();
		gain.gain.value = 0;
		if (context.audioWorklet && typeof context.audioWorklet.addModule === 'function') {
			var url = URL.createObjectURL(new Blob([workletSource()], {type: 'application/javascript'}));
			return context.audioWorklet.addModule(url).then(function () {
				URL.revokeObjectURL(url);
				var worklet = new AudioWorkletNode(context, 'mtai-pcm');
				worklet.port.onmessage = function (event) {
					pushSamples(event.data);
				};
				source.connect(worklet);
				worklet.connect(gain);
				gain.connect(context.destination);
				state.node = worklet;
			}).catch(function () {
				URL.revokeObjectURL(url);
				attachScriptProcessor(context, source, gain);
			});
		}
		attachScriptProcessor(context, source, gain);
		return Promise.resolve();
	}

	function attachScriptProcessor(context, source, gain) {
		var processor = context.createScriptProcessor(4096, 1, 1);
		processor.onaudioprocess = function (event) {
			pushSamples(downsampleFloat(event.inputBuffer.getChannelData(0), context.sampleRate));
		};
		source.connect(processor);
		processor.connect(gain);
		gain.connect(context.destination);
		state.node = processor;
	}

	function start() {
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			notify('Браузер не даёт доступ к микрофону');
			return;
		}
		state.starting = true;
		state.chunks = [];
		navigator.mediaDevices.getUserMedia({
			audio: {channelCount: 1, echoCancellation: true, noiseSuppression: true},
		}).then(function (stream) {
			state.stream = stream;
			state.context = new (window.AudioContext || window.webkitAudioContext)();
			var ready = state.context.state === 'suspended' ? state.context.resume() : Promise.resolve();
			return ready.then(function () {
				return attachProcessor(state.context, state.context.createMediaStreamSource(stream));
			});
		}).then(function () {
			state.starting = false;
			state.recording = true;
			state.startedAt = Date.now();
			setRecordingUi(true);
			state.timer = setInterval(function () {
				var elapsed = (Date.now() - state.startedAt) / 1000;
				eachButton(function (node) {
					var timer = node.querySelector('.mtai-voice__timer');
					if (timer) {
						timer.textContent = formatTime(elapsed);
					}
				});
				if (elapsed >= state.maxSeconds) {
					stop(false);
				}
			}, 200);
		}).catch(function () {
			state.starting = false;
			state.recording = false;
			cleanupAudio();
			setRecordingUi(false);
			notify('Нет доступа к микрофону');
		});
	}

	function stop(cancelled) {
		if (!state.recording && !state.stream) {
			return;
		}
		var chunks = state.chunks.slice();
		state.recording = false;
		state.starting = false;
		cleanupAudio();
		setRecordingUi(false);
		state.chunks = [];
		if (cancelled || !chunks.length) {
			if (!cancelled) {
				notify('Запись пустая');
			}
			return;
		}
		send(encodeWav(chunks));
	}

	function send(blob) {
		setBusy(true);
		var data = new FormData();
		data.append('file', blob, 'ai-voice.wav');
		BX.ajax.runAction('mtai:aiagent.voice.send', {data: data}).then(function () {
			setBusy(false);
		}).catch(function (response) {
			setBusy(false);
			var message = response && response.errors && response.errors[0] && response.errors[0].message;
			notify(message || 'Не удалось отправить голосовое');
		});
	}

	BX.Mtai.Aiagent.Voice = {
		start: function (options) {
			options = options || {};
			state.botId = String(options.botId || '');
			state.maxSeconds = Math.max(5, parseInt(options.maxSeconds, 10) || 60);
			sync();
			if (state.observer) {
				return;
			}
			var queued = false;
			state.observer = new MutationObserver(function () {
				if (queued) {
					return;
				}
				queued = true;
				setTimeout(function () {
					queued = false;
					sync();
				}, 200);
			});
			state.observer.observe(document.body, {childList: true, subtree: true});
			setInterval(sync, 700);
		},
	};
})();
