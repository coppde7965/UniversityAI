from fastapi import APIRouter, HTTPException, Depends, status
from fastapi.security import OAuth2PasswordBearer, OAuth2PasswordRequestForm
from pydantic import BaseModel
from typing import Literal, Annotated
from datetime import datetime, timedelta, timezone
import jwt

from app.application.users.use_cases import (
    get_users,
    get_user_by_id,
    create_user,
    update_user,
    delete_user,
)
from app.infrastructure.users.memory_repository import InMemoryUserRepository
from app.presentation.schemas.user import UserRead, UserCreate

router = APIRouter(tags=["users"])
repository = InMemoryUserRepository()

SECRET_KEY = "change-me-in-production"
ALGORITHM = "HS256"
ACCESS_TOKEN_EXPIRE_MINUTES = 60
oauth2_scheme = OAuth2PasswordBearer(tokenUrl="/users/login")

class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"

class UserMeResponse(BaseModel):
    id: int
    name: str
    role: Literal["teacher", "student", "assistant", "admin"]

def create_access_token(data: dict, expires_delta: timedelta | None = None):
    to_encode = data.copy()
    expire = datetime.now(timezone.utc) + (expires_delta or timedelta(minutes=ACCESS_TOKEN_EXPIRE_MINUTES))
    to_encode.update({"exp": expire})
    return jwt.encode(to_encode, SECRET_KEY, algorithm=ALGORITHM)

def get_current_user(token: Annotated[str, Depends(oauth2_scheme)]):
    credentials_exception = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Could not validate credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )
    try:
        payload = jwt.decode(token, SECRET_KEY, algorithms=[ALGORITHM])
        user_id = payload.get("sub")
        if user_id is None:
            raise credentials_exception
        user = get_user_by_id(int(user_id), repository)
        if not user:
            raise credentials_exception
        return user
    except Exception:
        raise credentials_exception

@router.get("/", response_model=list[UserRead])
def list_users():
    return get_users(repository)

@router.post("/", response_model=UserRead)
def add_user(user_data: UserCreate):
    return create_user(user_data, repository)

@router.post("/register", response_model=UserRead)
def register(user_data: UserCreate):
    return create_user(user_data, repository)

@router.post("/login", response_model=TokenResponse)
def login(form_data: OAuth2PasswordRequestForm = Depends()):
    users = get_users(repository)
    user = next((u for u in users if u.name == form_data.username), None)
    if not user:
        raise HTTPException(status_code=401, detail="Incorrect username or password")
    token = create_access_token({"sub": str(user.id)})
    return TokenResponse(access_token=token)

@router.get("/me", response_model=UserMeResponse)
def me(current_user: Annotated[UserRead, Depends(get_current_user)]):
    return current_user

@router.get("/{user_id}", response_model=UserRead)
def get_user(user_id: int):
    user = get_user_by_id(user_id, repository)
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
    return user

@router.put("/{user_id}", response_model=UserRead)
def modify_user(user_id: int, user_data: UserCreate):
    user = update_user(user_id, user_data, repository)
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
    return user

@router.delete("/{user_id}")
def remove_user(user_id: int):
    success = delete_user(user_id, repository)
    if not success:
        raise HTTPException(status_code=404, detail="User not found")
    return {"message": f"User {user_id} deleted"}
