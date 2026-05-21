'use client';

import React from 'react';
import { useParams, useRouter } from 'next/navigation';
import WebRTCSession from '@/components/telemedicine/WebRTCSession';
import { Button, Typography, Layout } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';

const { Header, Content } = Layout;
const { Title } = Typography;

export default function TelemedicineSessionPage() {
    const params = useParams();
    const router = useRouter();
    const sessionId = parseInt(params.id as string);

    if (isNaN(sessionId)) {
        return <div>Invalid Session ID</div>;
    }

    return (
        <Layout style={{ minHeight: '100vh', background: '#0f172a' }}>
            <Header style={{ 
                background: 'rgba(15, 23, 42, 0.8)', 
                backdropFilter: 'blur(8px)',
                display: 'flex', 
                alignItems: 'center', 
                justifyContent: 'space-between',
                padding: '0 24px',
                borderBottom: '1px solid rgba(255,255,255,0.1)'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                    <Button 
                        ghost 
                        icon={<ArrowLeftOutlined />} 
                        onClick={() => router.back()}
                        style={{ border: 'none', color: 'white' }}
                    />
                    <Title level={4} style={{ margin: 0, color: 'white' }}>
                        Telemedicine Consultation
                    </Title>
                </div>
            </Header>
            <Content style={{ padding: 24 }}>
                <WebRTCSession 
                    sessionId={sessionId} 
                    onSessionEnd={() => router.push('/dashboard/telemedicine')}
                />
            </Content>
        </Layout>
    );
}
