from app.domain.users.entities import User

class InMemoryUserRepository:
    def get_by_id(self, user_id: int) -> User:
        return User(id=user_id, name=f"User {user_id}")
