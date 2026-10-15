import React, { useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  Modal,
  ScrollView,
  TextInput,
  TouchableOpacity,
  ActivityIndicator,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';
import { MobileApi } from '../../api/client';

interface AiChatModalProps {
  visible: boolean;
  onClose: () => void;
}

export const AiChatModal: React.FC<AiChatModalProps> = ({ visible, onClose }) => {
  const [messages, setMessages] = useState<{ role: 'user' | 'assistant'; content: string }[]>([
    {
      role: 'assistant',
      content: 'Assalam-o-Alaikum! Welcome to NexusBids AI Assistant. How can I help you with live bidding, NADRA KYC, or escrow payments today?',
    },
  ]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);

  const handleSend = async () => {
    if (!input.trim() || sending) return;
    const text = input.trim();
    setInput('');
    setMessages((prev) => [...prev, { role: 'user', content: text }]);
    setSending(true);

    try {
      const res = await MobileApi.sendAiChat(text);
      const reply = res.data?.reply || 'I am processing your auction request.';
      setMessages((prev) => [...prev, { role: 'assistant', content: reply }]);
    } catch {
      setMessages((prev) => [
        ...prev,
        { role: 'assistant', content: 'Connection error. Please try again shortly.' },
      ]);
    } finally {
      setSending(false);
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View style={styles.titleRow}>
              <View style={{ width: 34, height: 34, borderRadius: 10, backgroundColor: 'rgba(99, 102, 241, 0.2)', alignItems: 'center', justifyContent: 'center', marginRight: 10 }}>
                <Ionicons name="sparkles" size={18} color={Colors.primaryLight} />
              </View>
              <View>
                <Text style={styles.title}>NexusBids AI Assistant</Text>
                <Text style={styles.subtitle}>Pakistani Auction & Escrow Specialist</Text>
              </View>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.chatList}>
            {messages.map((m, idx) => (
              <View
                key={idx}
                style={[
                  styles.msgBubble,
                  m.role === 'user' ? styles.userBubble : styles.botBubble,
                ]}
              >
                <Text style={[styles.msgText, m.role === 'user' ? styles.userText : styles.botText]}>
                  {m.content}
                </Text>
              </View>
            ))}
            {sending && (
              <View style={[styles.msgBubble, styles.botBubble]}>
                <ActivityIndicator size="small" color={Colors.primary} />
              </View>
            )}
          </ScrollView>

          <View style={styles.inputBar}>
            <TextInput
              style={styles.input}
              placeholder="Ask a question..."
              placeholderTextColor={Colors.textDim}
              value={input}
              onChangeText={setInput}
            />
            <TouchableOpacity style={styles.sendBtn} onPress={handleSend} disabled={sending}>
              <Ionicons name="send" size={15} color="#ffffff" />
            </TouchableOpacity>
          </View>
        </View>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.8)',
    justifyContent: 'flex-end',
  },
  sheet: {
    backgroundColor: Colors.surface,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    height: '80%',
    padding: 16,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingBottom: 12,
    borderBottomWidth: 1,
    borderBottomColor: Colors.border,
  },
  titleRow: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  botIcon: {
    fontSize: 24,
    marginRight: 10,
  },
  title: {
    fontSize: 15,
    fontWeight: '800',
    color: Colors.text,
  },
  subtitle: {
    fontSize: 11,
    color: Colors.textMuted,
  },
  closeBtn: {
    width: 30,
    height: 30,
    borderRadius: 15,
    backgroundColor: Colors.surfaceLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeText: {
    color: Colors.textMuted,
    fontSize: 16,
    fontWeight: '700',
  },
  chatList: {
    flex: 1,
    paddingVertical: 12,
  },
  msgBubble: {
    padding: 12,
    borderRadius: 14,
    marginBottom: 10,
    maxWidth: '85%',
  },
  userBubble: {
    backgroundColor: Colors.primary,
    alignSelf: 'flex-end',
    borderBottomRightRadius: 2,
  },
  botBubble: {
    backgroundColor: Colors.card,
    borderWidth: 1,
    borderColor: Colors.border,
    alignSelf: 'flex-start',
    borderBottomLeftRadius: 2,
  },
  msgText: {
    fontSize: 13,
    lineHeight: 18,
  },
  userText: {
    color: '#ffffff',
    fontWeight: '600',
  },
  botText: {
    color: Colors.text,
  },
  inputBar: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingTop: 8,
  },
  input: {
    flex: 1,
    backgroundColor: Colors.card,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Colors.border,
    paddingHorizontal: 14,
    paddingVertical: 10,
    color: Colors.text,
    fontSize: 14,
    marginRight: 8,
  },
  sendBtn: {
    backgroundColor: Colors.primary,
    width: 44,
    height: 44,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendText: {
    color: '#ffffff',
    fontSize: 18,
    fontWeight: '900',
  },
});
