Feature: 課程大綱管理
  作為 AI Agent 系統
  我希望能夠管理課程大綱
  以便根據課綱自動發送通知

  Scenario: 建立新的課程大綱
    Given 我有一個課程大綱資料
      | course_id | title | description |
      | 1 | AI 的人工智慧 | 學習 AI 基礎 |
    When 我建立這個課程大綱
    Then 課程大綱應該被成功建立

  Scenario: 取得所有課程大綱
    Given 系統中有課程大綱
    When 我請求所有課程大綱
    Then 應該回傳課程大綱列表
