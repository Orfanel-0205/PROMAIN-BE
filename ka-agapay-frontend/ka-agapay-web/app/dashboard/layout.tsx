// app/dashboard/layout.tsx
'use client';

import React, { useState, useEffect } from 'react';
import { Layout, Menu, Avatar, Dropdown, Badge, Typography, Button } from 'antd';
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
    { key: '/dashboard', icon: <DashboardOutlined />, label: 'Dashboard' },
    { key: '/dashboard/queue', icon: <TeamOutlined />, label: 'Queue Management' },
    { key: '/dashboard/telemedicine', icon: <VideoCameraOutlined />, label: 'Telemedicine' },
    { key: '/dashboard/prescriptions', icon: <FileTextOutlined />, label: 'Prescriptions' },
    { key: '/dashboard/referrals', icon: <ShareAltOutlined />, label: 'Referrals' },
    { key: '/dashboard/inventory', icon: <MedicineBoxOutlined />, label: 'Inventory' },
    { key: '/dashboard/announcements', icon: <NotificationOutlined />, label: 'Announcements' },
    { key: '/dashboard/analytics', icon: <BarChartOutlined />, label: 'Analytics' },
    { key: '/dashboard/audit', icon: <AuditOutlined />, label: 'Audit Logs' },
];

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
    const [collapsed, setCollapsed] = useState(false);
    const [user, setUser] = useState<any>(null);
    const router = useRouter();
    const pathname = usePathname();

    useEffect(() => {
        const token = Cookies.get('ka_agapay_token');
        if (!token) { router.push('/login'); return; }
        const saved = Cookies.get('ka_agapay_user');
        if (saved) setUser(JSON.parse(saved));
    }, [router]);

    const handleLogout = () => {
        Cookies.remove('ka_agapay_token');
        Cookies.remove('ka_agapay_user');
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
        <Layout style={{ minHeight: '100vh' }}>
            {/* Sidebar */}
            <Sider
                collapsible
                collapsed={collapsed}
                onCollapse={setCollapsed}
                style={{ background: '#1e3a8a' }}
                width={240}
            >
                {/* Logo */}
                <div style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: '20px 16px',
                    borderBottom: '1px solid rgba(255,255,255,0.1)',
                }}>
                    <MedicineBoxOutlined style={{ color: 'white', fontSize: 24 }} />
                    {!collapsed && (
                        <Text style={{
                            color: 'white',
                            fontWeight: 700,
                            fontSize: 18,
                            marginLeft: 8,
                        }}>
                            Ka-agapay
                        </Text>
                    )}
                </div>

                {/* Navigation Menu */}
                <Menu
                    theme="dark"
                    mode="inline"
                    selectedKeys={[pathname]}
                    items={menuItems}
                    onClick={({ key }) => router.push(key)}
                    style={{ background: '#1e3a8a', border: 'none', marginTop: 8 }}
                />
            </Sider>

            <Layout>
                {/* Top Header */}
                <Header style={{
                    background: 'white',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '0 24px',
                    boxShadow: '0 1px 4px rgba(0,0,0,0.1)',
                }}>
                    <div>
                        <Text style={{ color: '#374151', fontWeight: 500 }}>
                            RHU Malasiqui, Pangasinan
                        </Text>
                    </div>

                    <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                        <Badge count={0} size="small">
                            <BellOutlined style={{ fontSize: 20, color: '#6b7280', cursor: 'pointer' }} />
                        </Badge>

                        <Dropdown menu={userMenu} placement="bottomRight">
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                                <Avatar icon={<UserOutlined />} style={{ background: '#1d4ed8' }} />
                                <Text style={{ color: '#374151' }}>
                                    {user?.first_name || 'Admin'}
                                </Text>
                            </div>
                        </Dropdown>
                    </div>
                </Header>

                {/* Page Content */}
                <Content style={{ margin: 24, minHeight: 280 }}>
                    {children}
                </Content>
            </Layout>
        </Layout>
    );
}