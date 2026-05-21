// app/(dashboard)/queue/page.tsx

'use client';

import React, { useEffect, useState } from 'react';
import {
    Table, Button, Tag, Space, Modal,
    Select, Form, Input, message,
    Typography, Card, Badge, Tooltip
} from 'antd';
import {
    PlusOutlined, RobotOutlined,
    CheckCircleOutlined, CloseCircleOutlined
} from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import api from '@/lib/api';
import type { QueueTicket } from '@/lib/types';

const { Title } = Typography;
const { Option } = Select;

const STATUS_COLORS: Record<string, string> = {
    waiting: 'blue',
    called: 'orange',
    in_service: 'green',
    completed: 'default',
    cancelled: 'red',
    no_show: 'volcano',
};

const PRIORITY_COLORS: Record<string, string> = {
    emergency: 'red',
    pregnant: 'pink',
    senior_citizen: 'orange',
    pwd: 'purple',
    pediatric: 'cyan',
    regular: 'default',
};

export default function QueuePage() {
    const [tickets, setTickets] = useState<QueueTicket[]>([]);
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('waiting');
    const [actionLoading, setActionLoading] = useState<number | null>(null);

    useEffect(() => {
        fetchQueue();
        // Live refresh every 30 seconds
        const interval = setInterval(fetchQueue, 30000);
        return () => clearInterval(interval);
    }, [statusFilter]);

    const fetchQueue = async () => {
        try {
            const res = await api.get('/queue', {
                params: { rhu_id: 1, status: statusFilter }
            });
            setTickets(res.data.data || []);
        } catch (err) {
            message.error('Failed to load queue');
        } finally {
            setLoading(false);
        }
    };

    const callNext = async () => {
        try {
            const res = await api.post('/queue/call-next', {
                rhu_id: 1,
                service_type: 'opd_consultation'
            });
            message.success(`Now calling: ${res.data.data?.ticket_number}`);
            fetchQueue();
        } catch (err) {
            message.error('No patients waiting.');
        }
    };

    const updateStatus = async (ticketId: number, status: string) => {
        setActionLoading(ticketId);
        try {
            await api.patch(`/queue/${ticketId}/status`, { status });
            message.success(`Status updated to ${status}`);
            fetchQueue();
        } catch (err: any) {
            message.error(err.response?.data?.message || 'Update failed');
        } finally {
            setActionLoading(null);
        }
    };

    const columns: ColumnsType<QueueTicket> = [
        {
            title: 'Ticket #',
            dataIndex: 'ticket_number',
            key: 'ticket_number',
            render: (val) => (
                <span className="font-mono font-bold text-blue-700">{val}</span>
            ),
        },
        {
            title: 'Resident',
            key: 'resident',
            render: (_, record) => (
                <div>
                    <div className="font-medium">{record.resident?.name}</div>
                    <div className="text-xs text-gray-500">{record.resident?.barangay}</div>
                </div>
            ),
        },
        {
            title: 'Priority',
            key: 'priority',
            render: (_, record) => (
                <Tag color={PRIORITY_COLORS[record.priority?.category] || 'default'}>
                    {record.priority?.category?.replace('_', ' ').toUpperCase()}
                    {' '}({record.priority?.score})
                </Tag>
            ),
            sorter: (a, b) => (b.priority?.score || 0) - (a.priority?.score || 0),
        },
        {
            title: 'Service',
            dataIndex: 'service_type',
            key: 'service_type',
            render: (val) => (
                <Tag>{val?.replace(/_/g, ' ').toUpperCase()}</Tag>
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
            title: 'Wait (mins)',
            key: 'wait',
            render: (_, record) => (
                <span>{record.performance?.wait_time_minutes ?? '—'}</span>
            ),
        },
        {
            title: 'Actions',
            key: 'actions',
            render: (_, record) => (
                <Space>
                    {record.status === 'waiting' && (
                        <Button
                            size="small"
                            type="primary"
                            loading={actionLoading === record.id}
                            onClick={() => updateStatus(record.id, 'called')}
                        >
                            Call
                        </Button>
                    )}
                    {record.status === 'called' && (
                        <Button
                            size="small"
                            type="primary"
                            ghost
                            loading={actionLoading === record.id}
                            onClick={() => updateStatus(record.id, 'in_service')}
                        >
                            Serve
                        </Button>
                    )}
                    {record.status === 'in_service' && (
                        <Button
                            size="small"
                            icon={<CheckCircleOutlined />}
                            loading={actionLoading === record.id}
                            onClick={() => updateStatus(record.id, 'completed')}
                            className="border-green-500 text-green-600"
                        >
                            Done
                        </Button>
                    )}
                    {['waiting', 'called'].includes(record.status) && (
                        <Button
                            size="small"
                            danger
                            icon={<CloseCircleOutlined />}
                            onClick={() => updateStatus(record.id, 'no_show')}
                        >
                            No Show
                        </Button>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <div>
            <div className="flex justify-between items-center mb-6">
                <Title level={3} className="!mb-0">Queue Management</Title>
                <Space>
                    <Select
                        value={statusFilter}
                        onChange={setStatusFilter}
                        style={{ width: 140 }}
                    >
                        <Option value="waiting">Waiting</Option>
                        <Option value="called">Called</Option>
                        <Option value="in_service">In Service</Option>
                        <Option value="completed">Completed</Option>
                    </Select>
                    <Button
                        type="primary"
                        onClick={callNext}
                        className="bg-blue-600"
                    >
                        Call Next Patient
                    </Button>
                </Space>
            </div>

            <Card className="rounded-xl shadow-sm">
                <Table
                    columns={columns}
                    dataSource={tickets}
                    rowKey="id"
                    loading={loading}
                    pagination={{ pageSize: 15 }}
                    rowClassName={(record) =>
                        record.priority?.category === 'emergency'
                            ? 'bg-red-50'
                            : ''
                    }
                />
            </Card>
        </div>
    );
}