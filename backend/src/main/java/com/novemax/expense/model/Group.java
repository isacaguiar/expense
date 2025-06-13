package com.novemax.expense.model;

import jakarta.persistence.*;
import lombok.*;

import java.time.LocalDateTime;
import java.util.*;

@Entity
@Builder
@Setter
@Getter
@NoArgsConstructor
@AllArgsConstructor
@Table(name = "EX_GROUP")
public class Group {

    @Id
    private UUID id;

    private String name;
    private String description;
    private LocalDateTime createDate;

    private boolean deleted = false;

    @ManyToMany
    @JoinTable(
        name = "ex_groups_members",
        joinColumns = @JoinColumn(name = "group_id"),
        inverseJoinColumns = @JoinColumn(name = "user_id")
    )
    private List<User> users;

    public List<User> getUsers() {
        if (users == null) {
           users = new ArrayList<>();
        }
        return users;
    }
}