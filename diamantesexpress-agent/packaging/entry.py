"""Punto de entrada del ejecutable (PyInstaller)."""
import multiprocessing
import sys

from dxagent.cli import main

if __name__ == "__main__":
    multiprocessing.freeze_support()
    sys.exit(main())
