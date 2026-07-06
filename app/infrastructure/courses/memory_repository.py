from typing import List, Optional

from app.domain.courses.entities import Course


class CourseMemoryRepository:
    def __init__(self):
        self._courses: dict[int, Course] = {}
        self._counter: int = 0
        self._seed_data()

    def _seed_data(self):
        self.add("Introduction to Computer Science", "Basic CS concepts", 3)
        self.add("Data Structures", "Learn about arrays, linked lists, trees", 4)

    def add(self, title: str, description: str = "", credits: int = 3) -> Course:
        self._counter += 1
        course = Course(
            id=self._counter,
            title=title,
            description=description,
            credits=credits,
        )
        self._courses[self._counter] = course
        return course

    def get_all(self) -> List[Course]:
        return list(self._courses.values())

    def get_by_id(self, course_id: int) -> Optional[Course]:
        return self._courses.get(course_id)

    def delete(self, course_id: int) -> bool:
        if course_id in self._courses:
            del self._courses[course_id]
            return True
        return False
