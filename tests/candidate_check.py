#!/usr/bin/env python3
"""Compatibility entry point for the optional developer candidate."""
import subprocess
import sys
from pathlib import Path

subprocess.run([sys.executable, str(Path(__file__).with_name('artifact_check.py')), '--candidate'], check=True)
