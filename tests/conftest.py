import pytest
from app.main import create_app

@pytest.fixture
def client():
    from fastapi.testclient import TestClient
    app = create_app()
    return TestClient(app)
