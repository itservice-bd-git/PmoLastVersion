/* Avatar Electric PMO - ตรวจก่อน: Sub Task ที่สถานะไม่ตรงกับ Checklist (อ่านอย่างเดียว ไม่แก้ข้อมูล) */
/* รันใน phpMyAdmin (แท็บ SQL) ก่อนรัน 2026_11_16_subtask_status.sql เพื่อดูว่างานไหนจะถูกเปลี่ยน */
/* แสดงงานที่ติ๊ก Checklist ครบแล้ว แต่ยังไม่เป็นเสร็จงาน = งานที่ไฟล์นั้นจะเปลี่ยนเป็นเสร็จงานแล้ว */

SELECT
    x.project_no AS `โครงการ`,
    x.project_status AS `สถานะโครงการ`,
    x.mo_no AS `ตู้`,
    x.subtask_name AS `งาน`,
    x.department AS `แผนก`,
    x.done AS `ติ๊กแล้ว`,
    x.total AS `ทั้งหมด`,
    x.due_date AS `กำหนดจบ`,
    x.assignment_status AS `สถานะเดิม`,
    'COMPLETED' AS `สถานะใหม่`
FROM (
    SELECT
        s.id, s.name AS subtask_name, s.assignment_status, s.due_date,
        p.project_no, p.status AS project_status, c.mo_no, d.name AS department,
        ck.total, ck.done
    FROM `cabinet_subtasks` s
    JOIN (
        SELECT cabinet_subtask_id, COUNT(*) AS total, SUM(is_completed = 1) AS done
        FROM `cabinet_checklists` WHERE deleted_at IS NULL GROUP BY cabinet_subtask_id
    ) ck ON ck.cabinet_subtask_id = s.id
    JOIN `cabinet_tasks` t ON t.id = s.cabinet_task_id
    JOIN `cabinets` c ON c.id = t.cabinet_id
    JOIN `projects` p ON p.id = c.project_id
    LEFT JOIN `departments` d ON d.id = s.department_id
    WHERE s.deleted_at IS NULL
) x
WHERE x.done = x.total AND x.assignment_status <> 'COMPLETED'
ORDER BY x.project_no, x.id;
