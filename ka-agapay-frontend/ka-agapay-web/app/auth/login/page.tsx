// app/(auth)/login/page.tsx

'use client';
import React from 'react';
import { Form, Input, Button, Card, message, Typography } from 'antd';
import { UserOutlined, LockOutlined, MedicineBoxOutlined } from '@ant-design/icons';
import { useRouter } from 'next/navigation';
import Cookies from 'js-cookie';
import api from '@/lib/api';

const { Title, Text } = Typography;

interface LoginForm {
    mobile_number: string;
    password: string;
}

export default function LoginPage() {
    const router = useRouter();
    const [form] = Form.useForm();
    const [loading, setLoading] = React.useState(false);

    const onFinish = async (values: LoginForm) => {
        setLoading(true);
        try {
            const res = await api.post('/login', values);
            Cookies.set('ka_agapay_token', res.data.token, { expires: 7 });
            message.success('Login successful!');
            router.push('/');
        } catch (err: any) {
            message.error(err.response?.data?.message || 'Invalid credentials.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-blue-50 to-green-50
                        flex items-center justify-center p-4">
            <Card className="w-full max-w-md shadow-xl rounded-2xl">
                {/* Header */}
                <div className="text-center mb-8">
                    <div className="flex justify-center mb-4">
                        <div className="bg-blue-600 p-4 rounded-full">
                            <MedicineBoxOutlined className="text-white text-3xl" />
                        </div>
                    </div>
                    <Title level={2} className="!mb-1 !text-blue-800">
                        Ka-agapay
                    </Title>
                    <Text className="text-gray-500">
                        RHU1 & RHU2 Malasiqui Admin Portal
                    </Text>
                </div>

                {/* Form */}
                <Form
                    form={form}
                    onFinish={onFinish}
                    layout="vertical"
                    size="large"
                >
                    <Form.Item
                        name="mobile_number"
                        label="Mobile Number"
                        rules={[{ required: true, message: 'Enter your mobile number' }]}
                    >
                        <Input
                            prefix={<UserOutlined className="text-gray-400" />}
                            placeholder="09XXXXXXXXX"
                        />
                    </Form.Item>

                    <Form.Item
                        name="password"
                        label="Password"
                        rules={[{ required: true, message: 'Enter your password' }]}
                    >
                        <Input.Password
                            prefix={<LockOutlined className="text-gray-400" />}
                            placeholder="••••••••"
                        />
                    </Form.Item>

                    <Form.Item className="mt-6">
                        <Button
                            type="primary"
                            htmlType="submit"
                            loading={loading}
                            block
                            className="h-12 bg-blue-600 hover:bg-blue-700 text-lg"
                        >
                            Login
                        </Button>
                    </Form.Item>
                </Form>
            </Card>
        </div>
    );
}