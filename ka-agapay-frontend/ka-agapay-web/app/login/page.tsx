// app/login/page.tsx
'use client';

import React, { useState } from 'react';
import { Form, Input, Button, Card, message, Typography } from 'antd';
import { UserOutlined, LockOutlined, MedicineBoxOutlined } from '@ant-design/icons';
import { useRouter } from 'next/navigation';
import Cookies from 'js-cookie';
import api from '@/lib/api';

const { Title, Text } = Typography;

export default function LoginPage() {
    const router = useRouter();
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);

    const onFinish = async (values: any) => {
        setLoading(true);
        try {
            const res = await api.post('/login', values);
            Cookies.set('ka_agapay_token', res.data.token, { expires: 7 });
            Cookies.set('ka_agapay_user', JSON.stringify(res.data.user), { expires: 7 });
            message.success('Login successful!');
            router.push('/dashboard');
        } catch (err: any) {
            message.error(err.response?.data?.message || 'Invalid credentials.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{
            minHeight: '100vh',
            background: 'linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '16px',
        }}>
            <Card
                style={{
                    width: '100%',
                    maxWidth: 420,
                    borderRadius: 16,
                    boxShadow: '0 10px 40px rgba(0,0,0,0.1)',
                    border: 'none',
                }}
            >
                {/* Logo */}
                <div style={{ textAlign: 'center', marginBottom: 32 }}>
                    <div style={{
                        display: 'inline-flex',
                        background: '#1d4ed8',
                        borderRadius: '50%',
                        padding: 16,
                        marginBottom: 16,
                    }}>
                        <MedicineBoxOutlined style={{ color: 'white', fontSize: 28 }} />
                    </div>
                    <Title level={2} style={{ marginBottom: 4, color: '#1e3a8a' }}>
                        Ka-agapay
                    </Title>
                    <Text style={{ color: '#6b7280' }}>
                        RHU1 & RHU2 Malasiqui, Pangasinan
                    </Text>
                </div>

                {/* Login Form */}
                <Form
                    form={form}
                    onFinish={onFinish}
                    layout="vertical"
                    size="large"
                >
                    <Form.Item
                        name="mobile_number"
                        label="Mobile Number"
                        rules={[{ required: true, message: 'Please enter your mobile number' }]}
                    >
                        <Input
                            prefix={<UserOutlined style={{ color: '#9ca3af' }} />}
                            placeholder="09XXXXXXXXX"
                        />
                    </Form.Item>

                    <Form.Item
                        name="password"
                        label="Password"
                        rules={[{ required: true, message: 'Please enter your password' }]}
                    >
                        <Input.Password
                            prefix={<LockOutlined style={{ color: '#9ca3af' }} />}
                            placeholder="Enter your password"
                        />
                    </Form.Item>

                    <Form.Item style={{ marginTop: 8 }}>
                        <Button
                            type="primary"
                            htmlType="submit"
                            loading={loading}
                            block
                            style={{
                                height: 48,
                                background: '#1d4ed8',
                                border: 'none',
                                borderRadius: 8,
                                fontSize: 16,
                            }}
                        >
                            Sign In
                        </Button>
                    </Form.Item>
                </Form>

                {/* Demo Credentials */}
                <div style={{
                    background: '#f8fafc',
                    borderRadius: 8,
                    padding: 12,
                    marginTop: 8,
                }}>
                    <Text style={{ fontSize: 12, color: '#6b7280' }}>
                        <strong>Demo accounts</strong> (password: password123)<br />
                        Admin: 09170000001 | MHO: 09170000002<br />
                        Staff: 09170000003 | BHW: 09170000004
                    </Text>
                </div>
            </Card>
        </div>
    );
}