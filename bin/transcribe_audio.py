#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Audio Transcription Script for RabinFlow Process Discovery
Transcribes uploaded interview audio (mp3, wav, m4a, ogg, webm, etc.) to Persian text.

PRIVACY & ARCHITECTURE:
- 'local' (default): Uses on-premise faster-whisper (or openai-whisper) on CPU (int8).
  No audio data ever leaves the local server / LAN.
- 'google': Uses Google Speech API (fa-IR) over HTTPS. Only used when explicitly configured via TRANSCRIBE_ENGINE=google.
"""

import sys
import os
import json
import subprocess
import tempfile
import io

# Force UTF-8 encoding on Windows to prevent any 'charmap' codec issues
if sys.platform.startswith('win'):
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        try:
            sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
            sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')
        except Exception:
            pass

def convert_to_wav(input_path):
    """Convert any audio file to 16kHz mono WAV using ffmpeg with dynamic audio normalization."""
    fd, temp_wav = tempfile.mkstemp(suffix='.wav')
    os.close(fd)
    
    cmd = [
        'ffmpeg', '-y', '-v', 'quiet',
        '-i', input_path,
        '-af', 'dynaudnorm',
        '-ar', '16000',
        '-ac', '1',
        '-f', 'wav',
        temp_wav
    ]
    try:
        subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return temp_wav
    except FileNotFoundError:
        if os.path.exists(temp_wav):
            try: os.remove(temp_wav)
            except: pass
        raise RuntimeError("ابزار ffmpeg بر روی سرور نصب یا در متغیر PATH یافت نشد. لطفاً ffmpeg را نصب فرمایید.")
    except Exception as e:
        if os.path.exists(temp_wav):
            try: os.remove(temp_wav)
            except: pass
        raise RuntimeError(f"تبدیل فایل صوتی با ffmpeg با خطا مواجه شد: {e}")

def transcribe_local(wav_path):
    """On-premise private transcription using faster-whisper or openai-whisper."""
    has_faster_whisper = False
    try:
        from faster_whisper import WhisperModel
        has_faster_whisper = True
    except ImportError:
        pass

    has_openai_whisper = False
    if not has_faster_whisper:
        try:
            import whisper
            has_openai_whisper = True
        except ImportError:
            pass

    if not has_faster_whisper and not has_openai_whisper:
        raise RuntimeError(
            "پکیج faster-whisper برای پردازش محلی و امن صوت بر روی سرور یافت نشد. "
            "جهت حفظ حریم خصوصی مصاحبه‌ها، با دستور pip install faster-whisper آن را نصب کنید "
            "یا در صورت نیاز به سرویس ابری، TRANSCRIBE_ENGINE=google را در تنظیمات فعال نمایید."
        )

    model_dir = os.environ.get(
        'WHISPER_MODEL_DIR',
        os.path.join(os.path.dirname(os.path.abspath(__file__)), 'models')
    )
    try:
        os.makedirs(model_dir, exist_ok=True)
    except Exception:
        model_dir = None

    if has_faster_whisper:
        # Load faster-whisper on CPU with int8 quantization
        model = WhisperModel('small', device='cpu', compute_type='int8', download_root=model_dir)
        segments, info = model.transcribe(wav_path, language='fa', beam_size=5)
        text_parts = [seg.text.strip() for seg in segments if seg.text.strip()]
        full_text = " ".join(text_parts).strip()
        return {
            "text": full_text,
            "partial": False,
            "errors": []
        }
    else:
        # Load openai-whisper
        model = whisper.load_model('small', download_root=model_dir)
        result = model.transcribe(wav_path, language='fa')
        return {
            "text": result.get("text", "").strip(),
            "partial": False,
            "errors": []
        }

def transcribe_google(wav_path, language="fa-IR"):
    """Cloud-based transcription using SpeechRecognition and Google Speech API."""
    try:
        import speech_recognition as sr
    except ImportError:
        raise RuntimeError(
            "پکیج SpeechRecognition برای اتصال به سرویس ابری گوگل نصب نیست. "
            "لطفاً با دستور pip install SpeechRecognition آن را بر روی سرور نصب نمایید."
        )

    recognizer = sr.Recognizer()
    recognizer.energy_threshold = 250
    recognizer.dynamic_energy_threshold = True

    with sr.AudioFile(wav_path) as source:
        audio_duration = source.DURATION
        full_text_parts = []
        errors = []
        
        if audio_duration <= 55:
            audio_data = recognizer.record(source)
            try:
                text = recognizer.recognize_google(audio_data, language=language)
                if text:
                    full_text_parts.append(text)
            except sr.UnknownValueError:
                pass
            except sr.RequestError as e:
                errors.append(f"Request error: {e}")
        else:
            offset = 0.0
            chunk_len = 45.0
            while offset < audio_duration:
                source_chunk = sr.AudioFile(wav_path)
                with source_chunk as s:
                    try:
                        audio_data = recognizer.record(s, duration=chunk_len, offset=offset)
                        part_text = recognizer.recognize_google(audio_data, language=language)
                        if part_text:
                            full_text_parts.append(part_text)
                    except sr.UnknownValueError:
                        pass
                    except sr.RequestError as e:
                        errors.append(f"Chunk at {int(offset)}s: {e}")
                offset += (chunk_len - 3.0)
                
        final_text = " ".join(full_text_parts).strip()
        is_partial = len(errors) > 0 and len(final_text) > 0
        if not final_text and errors:
            raise RuntimeError("خطا در ارتباط با سرویس ابری گوگل: " + "; ".join(errors))

        return {
            "text": final_text,
            "partial": is_partial,
            "errors": errors
        }

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "مسیر فایل صوتی مشخص نشده است."}, ensure_ascii=True))
        sys.exit(1)

    input_file = sys.argv[1]
    engine = sys.argv[2].lower() if len(sys.argv) > 2 else os.environ.get('TRANSCRIBE_ENGINE', 'local').lower()

    if not os.path.exists(input_file):
        print(json.dumps({"success": False, "error": f"فایل صوتی یافت نشد: {input_file}"}, ensure_ascii=True))
        sys.exit(1)

    temp_wav = None
    try:
        temp_wav = convert_to_wav(input_file)
        
        if engine == 'google':
            result = transcribe_google(temp_wav, language="fa-IR")
        elif engine == 'local':
            result = transcribe_local(temp_wav)
        else:
            raise RuntimeError(f"موتور تبدیل صوت ناشناخته است: '{engine}'. مقادیر معتبر: 'local' یا 'google'")

        text = result.get("text", "")
        if not text:
            print(json.dumps({
                "success": True,
                "text": "",
                "message": "هیچ گفتار واضحی در فایل صوتی تشخیص داده نشد یا صدا بسیار آهسته بود."
            }, ensure_ascii=True))
        else:
            print(json.dumps({
                "success": True,
                "text": text,
                "partial": result.get("partial", False),
                "warnings": result.get("errors", [])
            }, ensure_ascii=True))
            
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }, ensure_ascii=True))
    finally:
        if temp_wav and os.path.exists(temp_wav):
            try:
                os.remove(temp_wav)
            except:
                pass

if __name__ == '__main__':
    main()
