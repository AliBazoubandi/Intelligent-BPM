#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Audio Transcription Script for RabinFlow Process Discovery
Transcribes uploaded interview audio (mp3, wav, m4a, ogg, webm, etc.) to Persian text.
Uses ffmpeg to convert to 16kHz mono WAV with dynamic audio normalization,
then uses SpeechRecognition with Google Speech API (fa-IR).
Guarantees ASCII-only JSON output (ensure_ascii=True) to avoid Windows charmap errors.
"""

import sys
import os
import json
import subprocess
import tempfile
import io
import speech_recognition as sr

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
    except Exception as e:
        if os.path.exists(temp_wav):
            try:
                os.remove(temp_wav)
            except:
                pass
        raise RuntimeError(f"FFmpeg conversion failed: {e}")

def transcribe_wav(wav_path, language="fa-IR"):
    """Transcribe WAV audio using SpeechRecognition."""
    recognizer = sr.Recognizer()
    recognizer.energy_threshold = 250
    recognizer.dynamic_energy_threshold = True

    with sr.AudioFile(wav_path) as source:
        audio_duration = source.DURATION
        full_text_parts = []
        
        # If audio is short (under 55 seconds), transcribe in one pass
        if audio_duration <= 55:
            audio_data = recognizer.record(source)
            try:
                text = recognizer.recognize_google(audio_data, language=language)
                if text:
                    full_text_parts.append(text)
            except sr.UnknownValueError:
                pass
            except sr.RequestError as e:
                raise RuntimeError(f"Speech service error: {e}")
        else:
            # Chunk long audio into 45-second segments
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
                        pass
                offset += (chunk_len - 3.0)  # 3-second overlap to prevent cutoffs
                
        return " ".join(full_text_parts).strip()

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No input file provided"}, ensure_ascii=True))
        sys.exit(1)

    input_file = sys.argv[1]
    if not os.path.exists(input_file):
        print(json.dumps({"success": False, "error": f"File not found: {input_file}"}, ensure_ascii=True))
        sys.exit(1)

    temp_wav = None
    try:
        temp_wav = convert_to_wav(input_file)
        text = transcribe_wav(temp_wav, language="fa-IR")
        
        if not text:
            print(json.dumps({
                "success": True,
                "text": "",
                "message": "هیچ گفتار واضحی در فایل صوتی تشخیص داده نشد یا صدا بسیار آهسته بود."
            }, ensure_ascii=True))
        else:
            print(json.dumps({
                "success": True,
                "text": text
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
