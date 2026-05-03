// app/dashboard/page.tsx
'use client';

import React, { useEffect, useState } from 'react';
import {
    Row, Col, Card, Statistic, Table,
    Tag, Badge, Typography, Avatar,
    Progress, List, Spin, Alert
} from 'antd';
import {
    CalendarOutlined, TeamOutlined,
    VideoCameraOutlined, MedicineBoxOutlined,
    RiseOutlined, FallOutlined,
    RobotOutlined, WarningOutlined,
    CheckCircleOutlined, ClockCircleOutlined,
} from '@ant-design/icons';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid,
    Tooltip, ResponsiveContainer, PieChart, Pie,
    Cell, Legend,
} from 'recharts';
import api from '@/lib/api';

const { Title, Text } = Typography;

// ── Color Palette (matching your Panacea design) ──────────────────────────────
const COLORS = {
    blue: '#3b82f6',
    green: '#22c55e',
    orange: '#f97316',
    purple: '#a855f7',
    cyan: '#06b6d4',
    red: '#ef4444',
    yellow: '#eab308',
};

// ── Stat Card Component (top 4 cards in your design) ─────────────────────────
const StatCard = ({
    icon, title, subtitle, value, sub1, sub2, color
}: any) => (
    <Card
        style={{
            borderRadius: 16,
            border: '1px solid #f0f0f0',
            boxShadow: '0 2px 12px rgba(0,0,0,0.06)',
        }}
        bodyStyle={{ padding: 20 }}
    >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div style={{
                background: color + '20',
                borderRadius: 12,
                padding: 10,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}>
                {React.cloneElement(icon, { style: { fontSize: 22, color } })}
            </div>
            <Text style={{ fontSize: 11, color: '#9ca3af' }}>↗</Text>
        </div>

        <div style={{ marginTop: 12 }}>
            <Text style={{ fontSize: 13, color: '#6b7280', display: 'block' }}>{title}</Text>
            <Text style={{ fontSize: 11, color: '#9ca3af', display: 'block' }}>{subtitle}</Text>
        </div>

        <div style={{ marginTop: 12 }}>
            <Text style={{ fontSize: 32, fontWeight: 800, color: '#111827' }}>{value}</Text>
            <div style={{ marginTop: 4 }}>
                <Text style={{ fontSize: 11, color: '#6b7280' }}>{sub1}</Text>
                {sub2 && <Text style={{ fontSize: 11, color: '#6b7280', display: 'block' }}>{sub2}</Text>}
            </div>
        </div>
    </Card>
);

export default function DashboardPage() {
    const [data, setData] = useState<any>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchDashboard();
        const interval = setInterval(fetchDashboard, 120000);
        return () => clearInterval(interval);
    }, []);

    const fetchDashboard = async () => {
        try {
            const res = await api.get('/dashboard/admin?rhu_id=1');
            setData(res.data.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return (
            <div style={{ display: 'flex', justifyContent: 'center', padding: 80 }}>
                <Spin size="large" />
            </div>
        );
    }

    // Sample data for charts (replace with real API data)
    const patientStatsData = [
        { name: 'Emergency', value: 56, color: COLORS.red, change: '+37%' },
        { name: 'Routine', value: 45, color: COLORS.purple, change: '+15%' },
        { name: 'Queue', value: 34, color: COLORS.green, change: '+42%' },
        { name: 'Tele', value: 20, color: COLORS.yellow, change: '+8%' },
        { name: 'Referral', value: 16, color: COLORS.cyan, change: '+29%' },
    ];

    const riskData = [
        { name: 'High Risk', value: 12, color: COLORS.red },
        { name: 'Moderate Risk', value: 25, color: COLORS.orange },
        { name: 'Low Risk', value: 78, color: COLORS.green },
    ];

    // Today's telemedicine requests for the appointment overview table
    const recentRequests = [
        { time: '7:28 AM', name: 'Maria Santos', complaint: 'Ubo at lagnat', status: 'completed' },
        { time: '1:12 PM', name: 'Juan dela Cruz', complaint: 'Sakit ng tiyan', status: 'scheduled' },
        { time: '6:11 PM', name: 'Ana Reyes', complaint: 'Hirap huminga', status: 'pending' },
        { time: '2:31 PM', name: 'Pedro Gomez', complaint: 'Mataas na BP', status: 'completed' },
        { time: '4:11 AM', name: 'Rosa Garcia', complaint: 'Sakít ng ulo', status: 'scheduled' },
        { time: '10:33 AM', name: 'Carlo Bautista', complaint: 'Pamamaga ng paa', status: 'pending' },
    ];

    const statusConfig: Record<string, { color: string; icon: any }> = {
        completed: { color: COLORS.green, icon: <CheckCircleOutlined style={{ color: COLORS.green }} /> },
        scheduled: { color: COLORS.blue, icon: <ClockCircleOutlined style={{ color: COLORS.blue }} /> },
        pending: { color: COLORS.orange, icon: <WarningOutlined style={{ color: COLORS.orange }} /> },
    };

    return (
        <div style={{ fontFamily: '-apple-system, BlinkMacSystemFont, sans-serif' }}>

            {/* ── Page Header ────────────────────────────────────────────────── */}
            <div style={{ marginBottom: 24, display: 'flex', justifyContent: 'space-between' }}>
                <div>
                    <Title level={3} style={{ margin: 0, color: '#111827' }}>
                        <b>Dashboard</b>
                    </Title>
                    <Text style={{ color: '#6b7280' }}>
                        RHU Malasiqui — Real-time Operations
                    </Text>
                </div>
                <Text style={{ color: '#6b7280', fontSize: 13 }}>
                    {new Date().toLocaleDateString('en-PH', {
                        year: 'numeric', month: 'long', day: 'numeric'
                    })}
                </Text>
            </div>

            {/* ── Top Stat Cards ──────────────────────────────────────────────── */}
            <Row gutter={[16, 16]} style={{ marginBottom: 20 }}>
                <Col xs={24} sm={12} lg={6}>
                    <StatCard
                        icon={<CalendarOutlined />}
                        title="Appointments"
                        subtitle="Today"
                        value={data?.today?.queue?.total ?? 98}
                        sub1={`Now: ${data?.today?.queue?.waiting ?? 34}`}
                        sub2="Annual Change: 65%"
                        color={COLORS.green}
                    />
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <StatCard
                        icon={<TeamOutlined />}
                        title="Total Patients"
                        subtitle="Today"
                        value={data?.today?.telemedicine?.total ?? 87}
                        sub1={`New: ${data?.today?.telemedicine?.pending ?? 29}`}
                        sub2="Old Patients: 4"
                        color={COLORS.blue}
                    />
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <StatCard
                        icon={<MedicineBoxOutlined />}
                        title="Prescriptions"
                        subtitle="Today"
                        value={data?.today?.prescriptions?.total_issued ?? 64}
                        sub1={`Dispensed: ${data?.today?.prescriptions?.dispensed ?? 30}`}
                        sub2={`Pending: ${data?.today?.prescriptions?.pending_dispense ?? 34}`}
                        color={COLORS.orange}
                    />
                </Col>
                <Col xs={24} sm={12} lg={6}>
                    <StatCard
                        icon={<VideoCameraOutlined />}
                        title="Telemedicine"
                        subtitle="Today"
                        value={data?.today?.telemedicine?.total ?? 76}
                        sub1={`Completed: ${data?.today?.telemedicine?.completed ?? 72}`}
                        sub2={`Pending: ${data?.today?.telemedicine?.pending ?? 4}`}
                        color={COLORS.purple}
                    />
                </Col>
            </Row>

            {/* ── Main Content Row ────────────────────────────────────────────── */}
            <Row gutter={[16, 16]}>

                {/* Patient Risk Analytics */}
                <Col xs={24} lg={10}>
                    <Card
                        title={
                            <div>
                                <Text strong style={{ fontSize: 15 }}>Patient Risk Analytics</Text>
                                <br />
                                <Text style={{ fontSize: 11, color: '#9ca3af', fontWeight: 400 }}>
                                    Identifies high-risk patients based on AI predictive analytics
                                </Text>
                            </div>
                        }
                        style={{ borderRadius: 16, border: '1px solid #f0f0f0' }}
                    >
                        <Row gutter={16} align="middle">
                            {/* Donut Chart */}
                            <Col span={12}>
                                <PieChart width={160} height={160}>
                                    <Pie
                                        data={riskData}
                                        cx={75}
                                        cy={75}
                                        innerRadius={50}
                                        outerRadius={75}
                                        paddingAngle={3}
                                        dataKey="value"
                                    >
                                        {riskData.map((entry, index) => (
                                            <Cell key={index} fill={entry.color} />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </Col>

                            {/* Risk Legend */}
                            <Col span={12}>
                                {riskData.map((item) => (
                                    <div key={item.name} style={{ marginBottom: 12 }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                            <div style={{
                                                width: 10, height: 10, borderRadius: '50%',
                                                backgroundColor: item.color,
                                            }} />
                                            <Text strong style={{ fontSize: 14 }}>
                                                {item.value} Patients
                                            </Text>
                                        </div>
                                        <Text style={{ fontSize: 11, color: '#6b7280', marginLeft: 16 }}>
                                            {item.name}
                                        </Text>
                                    </div>
                                ))}
                            </Col>
                        </Row>

                        {/* AI Insights Box */}
                        <div style={{
                            background: 'linear-gradient(135deg, #f0fdf4, #eff6ff)',
                            borderRadius: 12,
                            padding: 14,
                            marginTop: 16,
                        }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8 }}>
                                <RobotOutlined style={{ color: COLORS.blue }} />
                                <Text strong style={{ fontSize: 13 }}>AI Insights:</Text>
                            </div>
                            <Text style={{ fontSize: 12, color: '#374151', lineHeight: '1.6' }}>
                                # Sepsis Risk Detected in 3 Patients<br />
                                # Chronic Disease Alert for 7 Patients<br />
                                # Model Confidence: 92%
                            </Text>
                        </div>
                    </Card>
                </Col>

                {/* Patient Statistics Bar Chart */}
                <Col xs={24} lg={14}>
                    <Card
                        title={
                            <div>
                                <Text strong style={{ fontSize: 15 }}>Patients Statistics</Text>
                                <br />
                                <Text style={{ fontSize: 11, color: '#9ca3af', fontWeight: 400 }}>
                                    Figuring out stats for better health choices
                                </Text>
                            </div>
                        }
                        style={{ borderRadius: 16, border: '1px solid #f0f0f0' }}
                    >
                        <ResponsiveContainer width="100%" height={220}>
                            <BarChart data={patientStatsData} barSize={40}>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f0f0f0" />
                                <XAxis dataKey="name" axisLine={false} tickLine={false} />
                                <YAxis axisLine={false} tickLine={false} />
                                <Tooltip
                                    formatter={(value) => [`${value}%`, 'Percentage']}
                                />
                                <Bar dataKey="value" radius={[6, 6, 0, 0]}>
                                    {patientStatsData.map((entry, index) => (
                                        <Cell key={index} fill={entry.color + '60'} stroke={entry.color} strokeWidth={2} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>

                        {/* Legend */}
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 8 }}>
                            {patientStatsData.map((item) => (
                                <div key={item.name} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                    <div style={{
                                        width: 8, height: 8, borderRadius: '50%',
                                        backgroundColor: item.color,
                                    }} />
                                    <Text style={{ fontSize: 11, color: '#6b7280' }}>{item.name}</Text>
                                    <Tag color="default" style={{ fontSize: 10, marginLeft: 2 }}>
                                        {item.change}
                                    </Tag>
                                </div>
                            ))}
                        </div>
                    </Card>
                </Col>
            </Row>

            {/* ── Appointment Overview ─────────────────────────────────────────── */}
            <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
                <Col xs={24} lg={14}>
                    <Card
                        title={
                            <div>
                                <Text strong style={{ fontSize: 15 }}>Appointment Overview</Text>
                                <br />
                                <Text style={{ fontSize: 11, color: '#9ca3af', fontWeight: 400 }}>
                                    Smart health appointment schedule
                                </Text>
                            </div>
                        }
                        style={{ borderRadius: 16, border: '1px solid #f0f0f0' }}
                        extra={
                            <div style={{ display: 'flex', gap: 24 }}>
                                {[
                                    { label: 'Total Scheduled', value: 1025 },
                                    { label: 'Completed', value: 780 },
                                    { label: 'Missed', value: 245 },
                                    { label: 'Cancelled', value: 17 },
                                ].map((item) => (
                                    <div key={item.label} style={{ textAlign: 'center' }}>
                                        <Text strong style={{ fontSize: 16 }}>{item.value}</Text>
                                        <Text style={{ fontSize: 10, color: '#9ca3af', display: 'block' }}>
                                            {item.label}
                                        </Text>
                                    </div>
                                ))}
                            </div>
                        }
                    >
                        <List
                            dataSource={recentRequests}
                            renderItem={(item) => (
                                <List.Item
                                    style={{ padding: '10px 0', borderBottom: '1px solid #f9fafb' }}
                                    actions={[statusConfig[item.status]?.icon]}
                                >
                                    <List.Item.Meta
                                        avatar={
                                            <Avatar
                                                style={{ backgroundColor: COLORS.blue }}
                                                size={36}
                                            >
                                                {item.name[0]}
                                            </Avatar>
                                        }
                                        title={
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                <Text style={{ fontSize: 11, color: '#9ca3af' }}>
                                                    {item.time}
                                                </Text>
                                                <Text style={{ fontSize: 13, fontWeight: 600 }}>
                                                    {item.name}
                                                </Text>
                                            </div>
                                        }
                                        description={
                                            <Text style={{ fontSize: 12, color: '#6b7280' }}>
                                                {item.complaint}
                                            </Text>
                                        }
                                    />
                                </List.Item>
                            )}
                        />
                    </Card>
                </Col>

                {/* Quick Stats Side Panel */}
                <Col xs={24} lg={10}>
                    <Card
                        title="Today's Summary"
                        style={{ borderRadius: 16, border: '1px solid #f0f0f0', marginBottom: 16 }}
                    >
                        {[
                            { label: 'Queue Waiting', value: data?.today?.queue?.waiting ?? 12, color: COLORS.blue, icon: '⏳' },
                            { label: 'Emergency Cases', value: data?.today?.telemedicine?.emergency ?? 3, color: COLORS.red, icon: '🚨' },
                            { label: 'Pending Referrals', value: data?.today?.referrals?.pending ?? 8, color: COLORS.orange, icon: '📋' },
                            { label: 'Low Stock Alerts', value: data?.inventory?.low_stock_count ?? 5, color: COLORS.yellow, icon: '💊' },
                        ].map((item) => (
                            <div
                                key={item.label}
                                style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'center',
                                    padding: '10px 0',
                                    borderBottom: '1px solid #f9fafb',
                                }}
                            >
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <span style={{ fontSize: 20 }}>{item.icon}</span>
                                    <Text style={{ fontSize: 13, color: '#374151' }}>{item.label}</Text>
                                </div>
                                <Tag color={item.color === COLORS.red ? 'red' : 'default'}
                                    style={{ fontSize: 14, fontWeight: 700, padding: '2px 10px' }}>
                                    {item.value}
                                </Tag>
                            </div>
                        ))}
                    </Card>
                </Col>
            </Row>
        </div>
    );
}