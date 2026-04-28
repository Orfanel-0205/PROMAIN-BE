// app/(dashboard)/page.tsx

'use client';

import React, { useEffect, useState } from 'react';
import {
    Row, Col, Card, Statistic, Badge, Spin,
    Alert, Typography, Table, Tag
} from 'antd';
import {
    TeamOutlined, VideoCameraOutlined,
    FileTextOutlined, ShareAltOutlined,
    MedicineBoxOutlined, RiseOutlined,
    WarningOutlined,
} from '@ant-design/icons';
import api from '@/lib/api';
import type { DashboardData } from '@/lib/types';

const { Title, Text } = Typography;

export default function DashboardPage() {
    const [data, setData] = useState<DashboardData | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchDashboard();
        // Auto-refresh every 2 minutes
        const interval = setInterval(fetchDashboard, 120000);
        return () => clearInterval(interval);
    }, []);

    const fetchDashboard = async () => {
        try {
            const res = await api.get('/v1/dashboard/admin?rhu_id=1');
            setData(res.data.data);
        } catch (err) {
            console.error('Dashboard fetch failed', err);
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return (
            <div className="flex justify-center items-center h-64">
                <Spin size="large" />
            </div>
        );
    }

    return (
        <div>
            <div className="mb-6">
                <Title level={3} className="!mb-1">
                    Operations Dashboard
                </Title>
                <Text className="text-gray-500">
                    RHU1 Malasiqui — Today's Overview
                </Text>
            </div>

            {/* Queue Stats */}
            <Row gutter={[16, 16]} className="mb-6">
                <Col xs={24} sm={12} lg={6}>
                    <Card className="rounded-xl shadow-sm border-0 bg-blue-50">
                        <Statistic
                            title="Waiting in Queue"
                            value={data?.today.queue.waiting ?? 0}
                            prefix={<TeamOutlined className="text-blue-600" />}
                            valueStyle={{ color: '#1d4ed8' }}
                        />
                        <Text className="text-gray-500 text-xs">
                            Avg wait: {data?.today.queue.avg_wait_minutes ?? 0} mins
                        </Text>
                    </Card>
                </Col>

                <Col xs={24} sm={12} lg={6}>
                    <Card className="rounded-xl shadow-sm border-0 bg-green-50">
                        <Statistic
                            title="Telemedicine Today"
                            value={data?.today.telemedicine.total ?? 0}
                            prefix={<VideoCameraOutlined className="text-green-600" />}
                            valueStyle={{ color: '#16a34a' }}
                        />
                        <Text className="text-gray-500 text-xs">
                            {data?.today.telemedicine.pending ?? 0} pending review
                        </Text>
                    </Card>
                </Col>

                <Col xs={24} sm={12} lg={6}>
                    <Card className="rounded-xl shadow-sm border-0 bg-purple-50">
                        <Statistic
                            title="Prescriptions Issued"
                            value={data?.today.prescriptions.total_issued ?? 0}
                            prefix={<FileTextOutlined className="text-purple-600" />}
                            valueStyle={{ color: '#7c3aed' }}
                        />
                        <Text className="text-gray-500 text-xs">
                            {data?.today.prescriptions.dispensed ?? 0} dispensed
                        </Text>
                    </Card>
                </Col>

                <Col xs={24} sm={12} lg={6}>
                    <Card className="rounded-xl shadow-sm border-0 bg-orange-50">
                        <Statistic
                            title="Pending Referrals"
                            value={data?.today.referrals.pending ?? 0}
                            prefix={<ShareAltOutlined className="text-orange-600" />}
                            valueStyle={{ color: '#ea580c' }}
                        />
                        {(data?.today.referrals.urgent ?? 0) > 0 && (
                            <Text className="text-red-500 text-xs font-medium">
                                ⚠ {data?.today.referrals.urgent} urgent
                            </Text>
                        )}
                    </Card>
                </Col>
            </Row>

            {/* Emergency Alert */}
            {(data?.today.telemedicine.emergency ?? 0) > 0 && (
                <Alert
                    message={`${data?.today.telemedicine.emergency} Emergency Telemedicine Request(s) Today`}
                    description="Please review and prioritize these cases immediately."
                    type="error"
                    showIcon
                    icon={<WarningOutlined />}
                    className="mb-6 rounded-xl"
                    action={
                        <a href="/telemedicine/requests?urgency=emergency">
                            View Now
                        </a>
                    }
                />
            )}
        </div>
    );
}