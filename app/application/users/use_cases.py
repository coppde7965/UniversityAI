from app.application.users.repository import UserRepository
from app.presentation.schemas.user import UserRead, UserCreate

def get_users(repository: UserRepository) -> list[UserRead]:
    return repository.get_all()

def get_user_by_id(user_id: int, repository: UserRepository) -> UserRead | None:
    return repository.get_by_id(user_id)

def create_user(user_data: UserCreate, repository: UserRepository) -> UserRead:
    return repository.create(user_data)

def update_user(user_id: int, user_data: UserCreate, repository: UserRepository) -> UserRead | None:
    return repository.update(user_id, user_data)

def delete_user(user_id: int, repository: UserRepository) -> bool:
    return repository.delete(user_id)