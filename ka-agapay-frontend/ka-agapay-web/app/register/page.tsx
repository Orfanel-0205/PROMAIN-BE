'use client';

import React, { useState } from 'react';
import {
    Form, Input, Button, Card, Select,
    message, Typography, Row, Col, Divider
} from 'antd';
import {
    UserOutlined, LockOutlined, MedicineBoxOutlined,
    PhoneOutlined, MailOutlined,
} from '@ant-design/icons';
import { useRouter } from 'next/navigation';
import Cookies from 'js-cookie';
import api from '@/lib/api';

const { Title, Text } = Typography;
const { Option } = Select;

export default function RegisterPage() {
    const router = useRouter();
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);

    const onFinish = async (values: any) => {
        setLoading(true);
        try {
            const res = await api.post('/register', values);
            if (res.data.token) {
                Cookies.set('ka_agapay_token', res.data.token, { expires: 7 });
                Cookies.set('ka_agapay_user', JSON.stringify(res.data.user), { expires: 7 });
            }
            message.success('Account created successfully!');
            router.push('/dashboard');
        } catch (err: any) {
            const errors = err.response?.data?.errors;
            if (errors) {
                const firstError = Object.values(errors).flat()[0] as string;
                message.error(firstError);
            } else {
                message.error(err.response?.data?.message || 'Registration failed.');
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{
            minHeight: '100vh',
            background: 'linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%)',
            display: 'flex', alignItems: 'center',
            justifyContent: 'center', padding: 24,
        }}>
            <Card style={{
                width: '100%', maxWidth: 480,
                borderRadius: 16, boxShadow: '0 10px 40px rgba(0,0,0,0.1)',
                border: 'none',
            }}>
                <div style={{ textAlign: 'center', marginBottom: 28 }}>
                    <div style={{
                        display: 'inline-flex', background: '#1d4ed8',
                        borderRadius: '50%', padding: 14, marginBottom: 12,
                    }}>
                        <MedicineBoxOutlined style={{ color: 'white', fontSize: 26 }} />
                    </div>
                    <Title level={2} style={{ marginBottom: 4, color: '#1e3a8a' }}>
                        Ka-agapay
                    </Title>
                    <Text style={{ color: '#6b7280' }}>
                        Create a new staff account — RHU Malasiqui
                    </Text>
                </div>

                <Form form={form} onFinish={onFinish} layout="vertical" size="large">
                    <Text style={{ fontSize: 11, fontWeight: 500, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                        Personal information
                    </Text>

                    <Row gutter={12} style={{ marginTop: 10 }}>
                        <Col span={12}>
                            <Form.Item name="first_name" label="First name"
                                rules={[{ required: true, message: 'Required' }]}>
                                <Input prefix={<UserOutlined style={{ color: '#9ca3af' }} />}
                                    placeholder="Maria" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="last_name" label="Last name"
                                rules={[{ required: true, message: 'Required' }]}>
                                <Input placeholder="Santos" />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Form.Item name="email" label="Email address"
                        rules={[{ required: true, type: 'email', message: 'Enter a valid email' }]}>
                        <Input prefix={<MailOutlined style={{ color: '#9ca3af' }} />}
                            placeholder="maria@rhu-malasiqui.gov.ph" />
                    </Form.Item>

                    <Form.Item name="mobile_number" label="Mobile number"
                        rules={[
                            { required: true, message: 'Required' },
                            { pattern: /^09\d{9}$/, message: 'Must be 11 digits starting with 09' },
                        ]}>
                        <Input prefix={<PhoneOutlined style={{ color: '#9ca3af' }} />}
                            placeholder="09XXXXXXXXX" maxLength={11} />
                    </Form.Item>

                    <Divider style={{ margin: '4px 0 16px' }} />
                    <Text style={{ fontSize: 11, fontWeight: 500, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                        Account details
                    </Text>

                    <Row gutter={12} style={{ marginTop: 10 }}>
                        <Col span={12}>
                            <Form.Item name="role" label="Role"
                                rules={[{ required: true, message: 'Required' }]}>
                                <Select placeholder="Select role">
                                    <Option value="staff_admin">Staff Admin</Option>
                                    <Option value="mho">MHO (Doctor)</Option>
                                    <Option value="bhw">BHW</Option>
                                    <Option value="super_admin">Super Admin</Option>
                                </Select>
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="rhu_id" label="RHU assignment"
                                rules={[{ required: true, message: 'Required' }]}>
                                <Select placeholder="Select RHU">
                                    <Option value={1}>RHU 1</Option>
                                    <Option value={2}>RHU 2</Option>
                                </Select>
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="password" label="Password"
                                rules={[{ required: true, min: 8, message: 'Min. 8 characters' }]}>
                                <Input.Password
                                    prefix={<LockOutlined style={{ color: '#9ca3af' }} />}
                                    placeholder="Min. 8 characters" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="password_confirmation" label="Confirm password"
                                dependencies={['password']}
                                rules={[
                                    { required: true, message: 'Required' },
                                    ({ getFieldValue }) => ({
                                        validator(_, value) {
                                            if (!value || getFieldValue('password') === value) {
                                                return Promise.resolve();
                                            }
                                            return Promise.reject('Passwords do not match');
                                        },
                                    }),
                                ]}>
                                <Input.Password
                                    prefix={<LockOutlined style={{ color: '#9ca3af' }} />}
                                    placeholder="Repeat password" />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Form.Item style={{ marginTop: 4 }}>
                        <Button type="primary" htmlType="submit" loading={loading} block
                            style={{ height: 44, background: '#1d4ed8', border: 'none', borderRadius: 8, fontSize: 15 }}>
                            Create account
                        </Button>
                    </Form.Item>
                </Form>

                <div style={{ textAlign: 'center', marginTop: 4 }}>
                    <Text style={{ fontSize: 13, color: '#6b7280' }}>
                        Already have an account?{' '}
                        <a onClick={() => router.push('/login')}
                            style={{ color: '#1d4ed8', cursor: 'pointer' }}>
                            Sign in
                        </a>
                    </Text>
                </div>
            </Card>
        </div>
    );
}