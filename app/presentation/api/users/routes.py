from fastapi import APIRouter, HTTPException
from app.application.users.use_cases import (
    get_users,
    get_user_by_id,
    create_user,
    update_user,
    delete_user,
)
from app.infrastructure.users.memory_repository import InMemoryUserRepository
from app.presentation.schemas.user import UserRead, UserCreate

router = APIRouter(prefix="/users", tags=["users"])
repository = InMemoryUserRepository()

@router.get("/", response_model=list[UserRead])
def list_users():
    return get_users(repository)

@router.get("/{user_id}", response_model=UserRead)
def get_user(user_id: int):
    user = get_user_by_id(user_id, repository)
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
    return user

@router.post("/", response_model=UserRead)
def add_user(user_data: UserCreate):
    return create_user(user_data, repository)

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