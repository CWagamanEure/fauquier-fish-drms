"""Database settings shared by the Python import/export tools."""
import os

HOST = os.getenv("DB_HOST", "127.0.0.1")
USER = os.getenv("DB_USER", "fishdb")
PASSWORD = os.getenv("DB_PASSWORD", "fishdb")
DATABASE = os.getenv("DB_NAME", "fishdb")
