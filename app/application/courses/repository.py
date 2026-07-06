from abc import ABC, abstractmethod
from typing import List, Optional

from app.domain.courses.entities import Course


class AbstractCourseRepository(ABC):
    @abstractmethod
    def add(self, title: str, description: str = "", credits: int = 3) -> Course:
        pass

    @abstractmethod
    def get_all(self) -> List[Course]:
        pass

    @abstractmethod
    def get_by_id(self, course_id: int) -> Optional[Course]:
        pass

    @abstractmethod
    def delete(self, course_id: int) -> bool:
        pass
