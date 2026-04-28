// app/(dashboard)/layout.tsx

'use client';

import React, { useState } from 'react';
import { Layout, Menu, Avatar, Dropdown, Badge, Typography } from 'antd';
import {
    DashboardOutlined,
    TeamOutlined,
    VideoCameraOutlined,
    FileTextOutlined,
    ShareAltOutlined,
    MedicineBoxOutlined,
    BarChartOutlined,
    BellOutlined,
    NotificationOutlined,
    AuditOutlined,
    LogoutOutlined,
    UserOutlined,
    MenuFoldOutlined,
    MenuUnfoldOutlined,
} from '@ant-design/icons';
import { useRouter, usePathname } from 'next/navigation';
import Cookies from 'js-cookie';

const { Sider, Header, Content } = Layout;
const { Text } = Typography;

const menuItems = [
    {
        key: '/',
        icon: <DashboardOutlined />,
        label: 'Dashboard',
    },
    {
        key: '/queue',
        icon: <TeamOutlined />,
        label: 'Queue Management',
    },
    {
        key: '/telemedicine',
        icon: <VideoCameraOutlined />,
        label: 'Telemedicine',
        children: [
            { key: '/telemedicine/requests', label: 'Requests' },
            { key: '/telemedicine/sessions', label: 'Sessions' },
        ],
    },
    {
        key: '/prescriptions',
        icon: <FileTextOutlined />,
        label: 'Prescriptions',
    },
    {
        key: '/referrals',
        icon: <ShareAltOutlined />,
        label: 'Referrals',
    },
    {
        key: '/inventory',
        icon: <MedicineBoxOutlined />,
        label: 'Inventory',
    },
    {
        key: '/announcements',
        icon: <NotificationOutlined />,
        label: 'Announcements',
    },
    {
        key: '/analytics',
        icon: <BarChartOutlined />,
        label: 'Analytics',
    },
    {
        key: '/audit',
        icon: <AuditOutlined />,
        label: 'Audit Logs',
    },
];

export default function DashboardLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const [collapsed, setCollapsed] = useState(false);
    const router = useRouter();
    const pathname = usePathname();

    const handleLogout = () => {
        Cookies.remove('ka_agapay_token');
        router.push('/login');
    };

    const userMenu = {
        items: [
            {
                key: 'logout',
                icon: <LogoutOutlined />,
                label: 'Logout',
                onClick: handleLogout,
                danger: true,
            },
        ],
    };

    return (
        <Layout className="min-h-screen">
            {/* Sidebar */}
            <Sider
                collapsible
                collapsed={collapsed}
                onCollapse={setCollapsed}
                className="!bg-blue-900"
                width={240}
            >
                {/* Logo */}
                <div className="flex items-center justify-center py-5 px-4">
                    <MedicineBoxOutlined className="text-white text-2xl" />
                    {!collapsed && (
                        <Text className="!text-white !font-bold text-lg ml-2">
                            Ka-agapay
                        </Text>
                    )}
                </div>

                {/* Navigation */}
                <Menu
                    theme="dark"
                    mode="inline"
                    selectedKeys={[pathname]}
                    defaultOpenKeys={['/telemedicine']}
                    items={menuItems}
                    onClick={({ key }) => router.push(key)}
                    className="!bg-blue-900 !border-none"
                />
            </Sider>

            <Layout>
                {/* Top Header */}
                <Header className="!bg-white flex items-center justify-between px-6 shadow-sm">
                    <div className="flex items-center gap-4">
                        {/* RHU selector */}
                        <Text className="text-gray-600 font-medium">
                            RHU1 — Malasiqui, Pangasinan
                        </Text>
                    </div>

                    <div className="flex items-center gap-4">
                        {/* Notifications */}
                        <Badge count={3} size="small">
                            <BellOutlined className="text-xl cursor-pointer text-gray-600" />
                        </Badge>

                        {/* User Avatar */}
                        <Dropdown menu={userMenu} placement="bottomRight">
                            <div className="flex items-center gap-2 cursor-pointer">
                                <Avatar
                                    icon={<UserOutlined />}
                                    className="bg-blue-600"
                                />
                                <Text className="hidden md:block text-gray-700">
                                    Admin
                                </Text>
                            </div>
                        </Dropdown>
                    </div>
                </Header>

                {/* Page Content */}
                <Content className="m-6">
                    {children}
                </Content>
            </Layout>
        </Layout>
    );
}