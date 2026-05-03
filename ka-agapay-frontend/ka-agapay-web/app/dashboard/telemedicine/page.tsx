// app/dashboard/telemedicine/page.tsx
'use client';

import React, { useEffect, useState } from 'react';
import {
    Table, Button, Tag, Space, Modal, Form,
    Input, Select, DatePicker, TimePicker,
    message, Typography, Card, Badge, Tabs,
    Avatar, Tooltip, Alert,
} from 'antd';
import {
    VideoCameraOutlined, CheckOutlined,
    CloseOutlined, CalendarOutlined,
    UserOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import dayjs from 'dayjs';
import api from '@/lib/api';

const { Title, Text } = Typography;
const { Option } = Select;

const URGENCY_COLORS = {
    routine: 'blue',
    urgent: 'orange',
    emergency: 'red',
};

const STATUS_COLORS: Record<string, string> = {
    pending: 'gold',
    screened: 'blue',
    scheduled: 'geekblue',
    completed: 'green',
    rejected: 'red',
    cancelled: 'default',
};

export default function TelemedicinePage() {
    const [requests, setRequests] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [screenModal, setScreenModal] = useState<any>(null);
    const [scheduleModal, setScheduleModal] = useState<any>(null);
    const [activeTab, setActiveTab] = useState('pending');
    const [form] = Form.useForm();

    useEffect(() => { fetchRequests(); }, [activeTab]);

    const fetchRequests = async () => {
        setLoading(true);
        try {
            const res = await api.get('/telemedicine/requests', {
                params: { rhu_id: 1, status: activeTab }
            });
            setRequests(res.data.data || []);
        } catch { message.error('Failed to load requests'); }
        finally { setLoading(false); }
    };

    const handleScreen = async (values: any) => {
        try {
            await api.patch(`/telemedicine/requests/${screenModal.id}/screen`, {
                decision: values.decision,
                screening_notes: values.screening_notes,
                rejection_reason: values.rejection_reason,
                schedule_now: values.schedule_now || false,
                assigned_doctor_id: values.assigned_doctor_id,
                scheduled_date: values.scheduled_date?.format('YYYY-MM-DD'),
                scheduled_time: values.scheduled_time?.format('HH:mm'),
                session_mode: 'in_app',
            });
            message.success('Request screened successfully.');
            setScreenModal(null);
            form.resetFields();
            fetchRequests();
        } catch (err: any) {
            message.error(err.response?.data?.message || 'Failed to screen request.');
        }
    };

    const columns: ColumnsType<any> = [
        {
            title: 'Patient',
            key: 'patient',
            render: (_, record) => (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <Avatar icon={<UserOutlined />} style={{ backgroundColor: '#1d4ed8' }} />
                    <div>
                        <Text strong style={{ fontSize: 13 }}>
                            {record.resident?.name}
                        </Text>
                        <Text style={{ display: 'block', fontSize: 11, color: '#6b7280' }}>
                            {record.resident?.barangay}
                        </Text>
                    </div>
                </div>
            ),
        },
        {
            title: 'Chief Complaint',
            dataIndex: 'chief_complaint',
            key: 'chief_complaint',
            render: (val) => (
                <Text style={{ fontSize: 13 }} ellipsis={{ tooltip: val }}>
                    {val}
                </Text>
            ),
        },
        {
            title: 'Urgency',
            dataIndex: 'urgency_level',
            key: 'urgency_level',
            render: (val) => (
                <Tag color={URGENCY_COLORS[val as keyof typeof URGENCY_COLORS]}>
                    {val?.toUpperCase()}
                </Tag>
            ),
        },
        {
            title: 'Status',
            dataIndex: 'status',
            key: 'status',
            render: (val) => (
                <Badge
                    color={STATUS_COLORS[val] || 'default'}
                    text={val?.toUpperCase()}
                />
            ),
        },
        {
            title: 'Submitted',
            dataIndex: 'created_at',
            key: 'created_at',
            render: (val) => (
                <Text style={{ fontSize: 12, color: '#6b7280' }}>
                    {dayjs(val).format('MMM D, h:mm A')}
                </Text>
            ),
        },
        {
            title: 'Actions',
            key: 'actions',
            render: (_, record) => (
                <Space>
                    {record.status === 'pending' && (
                        <Button
                            size="small"
                            type="primary"
                            icon={<CheckOutlined />}
                            onClick={() => {
                                setScreenModal(record);
                                form.resetFields();
                            }}
                        >
                            Screen
                        </Button>
                    )}
                    {record.status === 'screened' && (
                        <Button
                            size="small"
                            icon={<CalendarOutlined />}
                            onClick={() => setScheduleModal(record)}
                        >
                            Schedule
                        </Button>
                    )}
                    {record.session && (
                        <Button
                            size="small"
                            type="primary"
                            icon={<VideoCameraOutlined />}
                            onClick={() => {
                                window.open(
                                    `/dashboard/telemedicine/session/${record.session.id}`,
                                    '_blank'
                                );
                            }}
                            style={{ backgroundColor: '#16a34a', borderColor: '#16a34a' }}
                        >
                            Join
                        </Button>
                    )}
                </Space>
            ),
        },
    ];

    const tabItems = [
        { key: 'pending', label: <Badge dot color="gold">Pending</Badge> },
        { key: 'screened', label: 'Screened' },
        { key: 'scheduled', label: 'Scheduled' },
        { key: 'completed', label: 'Completed' },
    ];

    return (
        <div>
            <div style={{ marginBottom: 24 }}>
                <Title level={3} style={{ margin: 0 }}>Telemedicine Requests</Title>
                <Text style={{ color: '#6b7280' }}>
                    Manage teleconsultation requests for RHU Malasiqui
                </Text>
            </div>

            <Card
                style={{ borderRadius: 16, border: '1px solid #f0f0f0' }}
                bodyStyle={{ padding: 0 }}
            >
                <div style={{ padding: '16px 24px 0' }}>
                    <Tabs
                        activeKey={activeTab}
                        onChange={setActiveTab}
                        items={tabItems}
                    />
                </div>

                <Table
                    columns={columns}
                    dataSource={requests}
                    rowKey="id"
                    loading={loading}
                    pagination={{ pageSize: 10 }}
                    style={{ padding: '0 8px' }}
                    rowClassName={(record) =>
                        record.urgency_level === 'emergency' ? 'ant-table-row-danger' : ''
                    }
                />
            </Card>

            {/* Screen Modal */}
            <Modal
                title={`Screen Request — ${screenModal?.resident?.name}`}
                open={!!screenModal}
                onCancel={() => setScreenModal(null)}
                onOk={() => form.submit()}
                okText="Submit Decision"
                width={560}
            >
                {screenModal?.urgency_level === 'emergency' && (
                    <Alert
                        message="Emergency case — please review immediately."
                        type="error"
                        showIcon
                        style={{ marginBottom: 16 }}
                    />
                )}

                <div style={{
                    background: '#f8fafc', borderRadius: 8,
                    padding: 12, marginBottom: 16,
                }}>
                    <Text strong>Chief Complaint: </Text>
                    <Text>{screenModal?.chief_complaint}</Text>
                    <br />
                    <Text strong>Symptoms: </Text>
                    <Text>{screenModal?.symptoms?.join(', ') || 'None listed'}</Text>
                </div>

                <Form form={form} onFinish={handleScreen} layout="vertical">
                    <Form.Item name="decision" label="Decision" rules={[{ required: true }]}>
                        <Select placeholder="Approve or reject this request">
                            <Option value="approve">✅ Approve — Schedule consultation</Option>
                            <Option value="reject">❌ Reject — Does not meet criteria</Option>
                        </Select>
                    </Form.Item>

                    <Form.Item
                        noStyle
                        shouldUpdate={(prev, cur) => prev.decision !== cur.decision}
                    >
                        {({ getFieldValue }) => (
                            <>
                                {getFieldValue('decision') === 'reject' && (
                                    <Form.Item
                                        name="rejection_reason"
                                        label="Rejection Reason"
                                        rules={[{ required: true, message: 'Please enter a reason' }]}
                                    >
                                        <Input.TextArea rows={3} />
                                    </Form.Item>
                                )}

                                {getFieldValue('decision') === 'approve' && (
                                    <>
                                        <Form.Item name="schedule_now" valuePropName="checked">
                                            <Select placeholder="Schedule session now?">
                                                <Option value={true}>
                                                    Schedule immediately
                                                </Option>
                                                <Option value={false}>
                                                    Schedule later
                                                </Option>
                                            </Select>
                                        </Form.Item>

                                        <Form.Item name="assigned_doctor_id" label="Assign Doctor">
                                            <Select placeholder="Select doctor">
                                                <Option value={2}>Dr. Maria Santos (MHO)</Option>
                                            </Select>
                                        </Form.Item>

                                        <Form.Item name="scheduled_date" label="Date">
                                            <DatePicker
                                                style={{ width: '100%' }}
                                                disabledDate={(d) => d.isBefore(dayjs(), 'day')}
                                            />
                                        </Form.Item>

                                        <Form.Item name="scheduled_time" label="Time">
                                            <TimePicker
                                                style={{ width: '100%' }}
                                                format="HH:mm"
                                                minuteStep={15}
                                            />
                                        </Form.Item>
                                    </>
                                )}
                            </>
                        )}
                    </Form.Item>

                    <Form.Item name="screening_notes" label="Screening Notes">
                        <Input.TextArea
                            rows={3}
                            placeholder="Optional notes for the doctor..."
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </div>
    );
}